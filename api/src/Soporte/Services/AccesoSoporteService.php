<?php

namespace App\Soporte\Services;

use App\Auditoria\Constants\AuditoriaConstants;
use App\Auditoria\Enums\AccionAuditoria;
use App\Auditoria\Services\EmisorAuditoria;
use App\Empresas\Models\Empresa;
use App\Notificaciones\Enums\TipoNotificacion;
use App\Notificaciones\Services\NotificacionService;
use App\Soporte\Enums\EstadoAccesoSoporte;
use App\Soporte\Models\AccesoSoporte;
use App\Usuarios\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Acceso de soporte just-in-time del superadmin (Fase 4, decisiones de Luis 2026-11-10):
 * sin bypass general; el superadmin pide acceso a UNA empresa, un administrador de esa empresa
 * lo aprueba, el superadmin lo activa y solo entonces ve sus datos, solo en lectura, con
 * vencimiento corto, sin exportar, auditado y notificado a ambas partes.
 */
class AccesoSoporteService
{
    public const DURACION_MINIMA_MINUTOS = 5;

    public const DURACION_MAXIMA_MINUTOS = 120;

    public const DURACION_POR_DEFECTO_MINUTOS = 30;

    public function __construct(
        protected EmisorAuditoria $auditoria,
        protected NotificacionService $notificaciones,
    ) {}

    /** El acceso activo de un superadmin, si lo tiene y no venció; al vencer se marca expirado. Siempre consulta la BD: un caché en memoria de proceso sobrevive entre peticiones distintas en el mismo worker de PHP-FPM y dejaría ver un acceso ya revocado por otro worker. */
    public function accesoActivo(Usuario $usuario): ?AccesoSoporte
    {
        // Sin el accesor `es_superadmin`: se lee desde EmpresaScope, donde el accesor estaría reentrando.
        if ($usuario->empresa_id !== null) {
            return null;
        }

        $acceso = AccesoSoporte::query()
            ->where('superadmin_id', (int) $usuario->id)
            ->where('estado', EstadoAccesoSoporte::Activo)
            ->orderByDesc('activado_en')
            ->first();

        if ($acceso !== null && ! $acceso->estaVigente()) {
            $this->marcarExpirado($acceso);
            $acceso = null;
        }

        return $acceso;
    }

    public function solicitar(Usuario $superadmin, int $empresaId, string $motivo, int $duracionMinutos = self::DURACION_POR_DEFECTO_MINUTOS): AccesoSoporte
    {
        if ($superadmin->empresa_id !== null) {
            throw new AccessDeniedHttpException('Solo un superadministrador puede solicitar acceso de soporte.');
        }

        $empresa = Empresa::findOrFail($empresaId);
        $duracionMinutos = max(self::DURACION_MINIMA_MINUTOS, min(self::DURACION_MAXIMA_MINUTOS, $duracionMinutos));

        $pendiente = AccesoSoporte::query()
            ->where('superadmin_id', $superadmin->id)
            ->where('empresa_id', $empresa->id)
            ->whereIn('estado', [EstadoAccesoSoporte::Solicitado, EstadoAccesoSoporte::Aprobado, EstadoAccesoSoporte::Activo])
            ->exists();

        if ($pendiente) {
            throw new AccessDeniedHttpException('Ya tienes un acceso de soporte pendiente o activo para esta empresa.');
        }

        $acceso = DB::transaction(function () use ($superadmin, $empresa, $motivo, $duracionMinutos) {
            $acceso = AccesoSoporte::create([
                'superadmin_id' => $superadmin->id,
                'empresa_id' => $empresa->id,
                'motivo' => $motivo,
                'estado' => EstadoAccesoSoporte::Solicitado,
                'duracion_minutos' => $duracionMinutos,
                'solicitado_en' => now(),
            ]);

            $this->auditoria->registrar(
                AccionAuditoria::Crear,
                AuditoriaConstants::MODULO_SOPORTE,
                "Solicitud de acceso de soporte a la empresa {$empresa->nombre}",
                'acceso_soporte',
                (int) $acceso->id,
                nuevos: ['empresa_id' => $empresa->id, 'motivo' => $motivo, 'duracion_minutos' => $duracionMinutos],
                motivo: $motivo,
                empresaId: (int) $empresa->id,
            );

            return $acceso;
        });

        foreach ($this->administradoresDe((int) $empresa->id) as $admin) {
            $this->notificaciones->notificar(
                (int) $admin->id,
                (int) $empresa->id,
                TipoNotificacion::AccesoSoporteSolicitado,
                'Solicitud de acceso de soporte',
                "El superadministrador {$superadmin->usuario} solicita acceso de solo lectura a {$empresa->nombre} por {$duracionMinutos} minutos. Motivo: {$motivo}",
                ['acceso_soporte_id' => $acceso->id],
            );
        }

        return $acceso;
    }

    public function aprobar(AccesoSoporte $acceso, Usuario $admin): AccesoSoporte
    {
        $this->exigirAdminDeLaEmpresa($acceso, $admin);

        if ($acceso->estado !== EstadoAccesoSoporte::Solicitado) {
            throw new AccessDeniedHttpException('Solo se puede aprobar un acceso en estado solicitado.');
        }

        $acceso->update(['estado' => EstadoAccesoSoporte::Aprobado, 'admin_aprobador_id' => $admin->id, 'aprobado_en' => now()]);

        $this->auditoria->registrar(
            AccionAuditoria::Aprobar,
            AuditoriaConstants::MODULO_SOPORTE,
            "Acceso de soporte aprobado para la empresa {$acceso->empresa_id}",
            'acceso_soporte',
            (int) $acceso->id,
            anteriores: ['estado' => EstadoAccesoSoporte::Solicitado->value],
            nuevos: ['estado' => EstadoAccesoSoporte::Aprobado->value, 'admin_aprobador_id' => $admin->id],
            empresaId: (int) $acceso->empresa_id,
        );

        $this->notificaciones->notificar(
            (int) $acceso->superadmin_id,
            (int) $acceso->empresa_id,
            TipoNotificacion::AccesoSoporteAprobado,
            'Acceso de soporte aprobado',
            "Tu acceso de solo lectura a la empresa fue aprobado por {$admin->usuario} por {$acceso->duracion_minutos} minutos. Actívalo cuando lo necesites.",
            ['acceso_soporte_id' => $acceso->id],
        );

        return $acceso->fresh();
    }

    public function rechazar(AccesoSoporte $acceso, Usuario $admin, string $motivo): AccesoSoporte
    {
        $this->exigirAdminDeLaEmpresa($acceso, $admin);

        if ($acceso->estado !== EstadoAccesoSoporte::Solicitado) {
            throw new AccessDeniedHttpException('Solo se puede rechazar un acceso en estado solicitado.');
        }

        $acceso->update(['estado' => EstadoAccesoSoporte::Rechazado]);

        $this->auditoria->registrar(
            AccionAuditoria::Rechazar,
            AuditoriaConstants::MODULO_SOPORTE,
            'Acceso de soporte rechazado',
            'acceso_soporte',
            (int) $acceso->id,
            anteriores: ['estado' => EstadoAccesoSoporte::Solicitado->value],
            nuevos: ['estado' => EstadoAccesoSoporte::Rechazado->value],
            motivo: $motivo,
            empresaId: (int) $acceso->empresa_id,
        );

        $this->notificaciones->notificar(
            (int) $acceso->superadmin_id,
            (int) $acceso->empresa_id,
            TipoNotificacion::AccesoSoporteRechazado,
            'Acceso de soporte rechazado',
            "Tu solicitud de acceso de soporte fue rechazada por {$admin->usuario}. Motivo: {$motivo}",
            ['acceso_soporte_id' => $acceso->id],
        );

        return $acceso->fresh();
    }

    public function activar(AccesoSoporte $acceso, Usuario $superadmin): AccesoSoporte
    {
        if ((int) $acceso->superadmin_id !== (int) $superadmin->id || $superadmin->empresa_id !== null) {
            throw new AccessDeniedHttpException('Solo el superadministrador solicitante puede activar su acceso.');
        }

        if ($acceso->estado !== EstadoAccesoSoporte::Aprobado) {
            throw new AccessDeniedHttpException('Solo se puede activar un acceso aprobado.');
        }

        $acceso->update([
            'estado' => EstadoAccesoSoporte::Activo,
            'activado_en' => now(),
            'expira_en' => now()->addMinutes($acceso->duracion_minutos),
        ]);

        $this->auditoria->registrar(
            AccionAuditoria::Modificar,
            AuditoriaConstants::MODULO_SOPORTE,
            'Acceso de soporte activado (solo lectura)',
            'acceso_soporte',
            (int) $acceso->id,
            anteriores: ['estado' => EstadoAccesoSoporte::Aprobado->value],
            nuevos: ['estado' => EstadoAccesoSoporte::Activo->value, 'expira_en' => $acceso->expira_en?->toIso8601String()],
            empresaId: (int) $acceso->empresa_id,
        );

        return $acceso->fresh();
    }

    public function revocar(AccesoSoporte $acceso, Usuario $actor): AccesoSoporte
    {
        $esPropio = (int) $acceso->superadmin_id === (int) $actor->id && $actor->empresa_id === null;

        if (! $esPropio) {
            $this->exigirAdminDeLaEmpresa($acceso, $actor);
        }

        if (! in_array($acceso->estado, [EstadoAccesoSoporte::Solicitado, EstadoAccesoSoporte::Aprobado, EstadoAccesoSoporte::Activo], true)) {
            throw new AccessDeniedHttpException('Este acceso ya no se puede revocar.');
        }

        $acceso->update(['estado' => EstadoAccesoSoporte::Revocado, 'revocado_en' => now()]);

        $this->auditoria->registrar(
            AccionAuditoria::Cancelar,
            AuditoriaConstants::MODULO_SOPORTE,
            'Acceso de soporte revocado',
            'acceso_soporte',
            (int) $acceso->id,
            nuevos: ['estado' => EstadoAccesoSoporte::Revocado->value],
            empresaId: (int) $acceso->empresa_id,
        );

        if (! $esPropio) {
            $this->notificaciones->notificar(
                (int) $acceso->superadmin_id,
                (int) $acceso->empresa_id,
                TipoNotificacion::AccesoSoporteRevocado,
                'Acceso de soporte revocado',
                "Tu acceso de soporte fue revocado por {$actor->usuario}.",
                ['acceso_soporte_id' => $acceso->id],
            );
        }

        return $acceso->fresh();
    }

    /** Marca como expirados los accesos activos vencidos; devuelve cuántos expiraron. */
    public function expirarVencidos(): int
    {
        $vencidos = AccesoSoporte::query()
            ->where('estado', EstadoAccesoSoporte::Activo)
            ->where('expira_en', '<=', now())
            ->get();

        foreach ($vencidos as $acceso) {
            $this->marcarExpirado($acceso);
        }

        return $vencidos->count();
    }

    private function marcarExpirado(AccesoSoporte $acceso): void
    {
        $acceso->update(['estado' => EstadoAccesoSoporte::Expirado]);

        $this->auditoria->registrar(
            AccionAuditoria::Cancelar,
            AuditoriaConstants::MODULO_SOPORTE,
            'Acceso de soporte expirado',
            'acceso_soporte',
            (int) $acceso->id,
            anteriores: ['estado' => EstadoAccesoSoporte::Activo->value],
            nuevos: ['estado' => EstadoAccesoSoporte::Expirado->value],
            empresaId: (int) $acceso->empresa_id,
        );
    }

    private function exigirAdminDeLaEmpresa(AccesoSoporte $acceso, Usuario $admin): void
    {
        $esAdmin = $admin->rol?->nombre === 'admin' && (int) $admin->empresa_id === (int) $acceso->empresa_id;

        if (! $esAdmin) {
            throw new AccessDeniedHttpException('Solo un administrador de esa empresa puede decidir sobre este acceso.');
        }
    }

    /** @return \Illuminate\Support\Collection<int, Usuario> */
    private function administradoresDe(int $empresaId)
    {
        return Usuario::query()
            ->where('empresa_id', $empresaId)
            ->whereHas('rol', fn ($consulta) => $consulta->where('nombre', 'admin'))
            ->get();
    }
}
