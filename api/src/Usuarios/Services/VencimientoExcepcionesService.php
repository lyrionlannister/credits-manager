<?php

namespace App\Usuarios\Services;

use App\Auditoria\Constants\AuditoriaConstants;
use App\Auditoria\Enums\AccionAuditoria;
use App\Auditoria\Services\EmisorAuditoria;
use App\Usuarios\Constants\PermisoCacheConstants;
use App\Usuarios\Models\Usuario;
use App\Usuarios\Services\PermisoCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Vencimiento automático de excepciones individuales: una excepción con vence_en pasado
 * deja de aplicar sin intervención manual — se borra la fila, así que una revocación vencida
 * devuelve el mando al rol y una concesión vencida deja de conceder.
 *
 * Cada vencimiento queda en la bitácora (Fase 10) y la llave de caché del usuario se invalida
 * en el acto, para que el permiso no sobreviva en caché ni un minuto más.
 */
class VencimientoExcepcionesService
{
    public function __construct(protected EmisorAuditoria $auditoria, protected PermisoCache $cachePermisos) {}

    /** @return int excepciones vencidas */
    public function vencer(): int
    {
        $vencidas = DB::table('usuario_permiso')
            ->join('permisos', 'permisos.id', '=', 'usuario_permiso.permiso_id')
            ->whereNotNull('usuario_permiso.vence_en')
            ->where('usuario_permiso.vence_en', '<=', now())
            ->select(['usuario_permiso.id', 'usuario_permiso.usuario_id', 'usuario_permiso.empresa_id', 'usuario_permiso.concedido', 'permisos.modulo', 'permisos.accion'])
            ->orderBy('usuario_permiso.id')
            ->get();

        foreach ($vencidas as $fila) {
            DB::transaction(function () use ($fila) {
                $usuario = Usuario::withoutGlobalScopes()->find($fila->usuario_id);

                $this->auditoria->registrar(
                    AccionAuditoria::CambioPermisos,
                    AuditoriaConstants::MODULO_USUARIOS,
                    "Excepción de {$usuario?->usuario} vencida",
                    'usuario',
                    (int) $fila->usuario_id,
                    ["{$fila->modulo}.{$fila->accion}" => (bool) $fila->concedido],
                    [],
                    'Vencimiento automático de la excepción individual',
                    null,
                    $fila->empresa_id ? (int) $fila->empresa_id : null,
                );

                DB::table('usuario_permiso')->where('id', $fila->id)->delete();

                Usuario::withoutGlobalScopes()->whereKey((int) $fila->usuario_id)->increment('version_permisos');
                $afectado = Usuario::withoutGlobalScopes()->find((int) $fila->usuario_id);

                if ($afectado !== null) {
                    $this->cachePermisos->invalidar($afectado);
                }

            });
        }

        return $vencidas->count();
    }
}
