<?php

namespace App\Usuarios\Services;

use App\Usuarios\Constants\UsuarioConstants;
use App\Usuarios\Models\Permiso;
use App\Usuarios\Models\Usuario;

/**
 * Diagnóstico «¿por qué este usuario no tiene este permiso?» (Fase 0).
 *
 * Replica la decisión de `Usuario::tienePermiso()` paso a paso y devuelve la etapa que decidió,
 * con un código de causa estable (`causa`) que también viaja en las respuestas 403. La causa
 * detallada nunca se expone al usuario final: solo al administrador que consulta el diagnóstico.
 */
class DiagnosticoPermisoService
{
    public const CAUSA_BYPASS_ADMIN = 'bypass_admin';

    public const CAUSA_EXCEPCION_CONCEDE = 'excepcion_concede';

    public const CAUSA_EXCEPCION_REVOCA = 'excepcion_revoca';

    public const CAUSA_PERMISO_ROL = 'permiso_rol';

    public const CAUSA_SIN_PERMISO = 'sin_permiso';

    public const CAUSA_PERMISO_NO_CATALOGADO = 'permiso_no_catalogado';

    public const CAUSA_DEBE_CAMBIAR_CLAVE = 'debe_cambiar_clave';

    public const CAUSA_SUPERADMIN_SIN_BYPASS = 'superadmin_sin_bypass';

    /** @return array{concedido: bool, causa: string, empresa_id: int|null, rol: string|null, permiso_existe: bool, excepcion: array|null, existe_en_otra_empresa: bool, detalle: string} */
    public function diagnosticar(Usuario $usuario, string $modulo, string $accion): array
    {
        $permiso = Permiso::query()->where('modulo', $modulo)->where('accion', $accion)->first();
        $existeEnOtraEmpresa = $this->existeEnOtraEmpresa($usuario, $modulo, $accion);

        if ($usuario->debe_cambiar_clave) {
            return $this->resultado(false, self::CAUSA_DEBE_CAMBIAR_CLAVE, $usuario, $permiso !== null, null, $existeEnOtraEmpresa,
                'El usuario tiene pendiente cambiar su clave temporal: todo acceso está bloqueado salvo el cambio de clave.');
        }

        // El bypass del admin y la falta de bypass del superadmin se imponen al catálogo:
        // el admin pasa aunque el permiso no esté sembrado, y el superadmin no pasa aunque lo esté.
        if ($usuario->rol?->nombre === UsuarioConstants::ROL_ADMIN) {
            return $this->resultado(true, self::CAUSA_BYPASS_ADMIN, $usuario, $permiso !== null, null, $existeEnOtraEmpresa,
                'El rol admin concede todos los permisos dentro de su empresa.');
        }

        if ($usuario->es_superadmin) {
            return $this->resultado(false, self::CAUSA_SUPERADMIN_SIN_BYPASS, $usuario, $permiso !== null, null, $existeEnOtraEmpresa,
                'El superadmin no tiene bypass general: solo entra a datos de una empresa con un acceso de soporte just-in-time aprobado.');
        }

        if ($permiso === null) {
            return $this->resultado(false, self::CAUSA_PERMISO_NO_CATALOGADO, $usuario, false, null, $existeEnOtraEmpresa,
                'El permiso no existe en el catálogo vigente: no está sembrado o fue retirado. Nadie puede tenerlo.');
        }

        $excepcion = $usuario->permisosExtraVigentes()
            ->where('modulo', $modulo)
            ->where('accion', $accion)
            ->first();

        if ($excepcion !== null) {
            $concedido = (bool) $excepcion->pivot->concedido;
            $datosExcepcion = [
                'concedido' => $concedido,
                'motivo' => $excepcion->pivot->motivo,
                'vence_en' => $excepcion->pivot->vence_en?->toDateTimeString(),
                'empresa_id' => $excepcion->pivot->empresa_id,
            ];

            return $this->resultado($concedido,
                $concedido ? self::CAUSA_EXCEPCION_CONCEDE : self::CAUSA_EXCEPCION_REVOCA,
                $usuario, true, $datosExcepcion, $existeEnOtraEmpresa,
                $concedido
                    ? 'Una excepción individual vigente concede este permiso aunque el rol no lo tenga.'
                    : 'Una excepción individual vigente revoca este permiso aunque el rol sí lo tenga.');
        }

        $porRol = (bool) $usuario->rol?->permisos()
            ->where('modulo', $modulo)
            ->where('accion', $accion)
            ->exists();

        if ($porRol) {
            return $this->resultado(true, self::CAUSA_PERMISO_ROL, $usuario, true, null, $existeEnOtraEmpresa,
                'El rol del usuario en esta empresa trae este permiso.');
        }

        return $this->resultado(false, self::CAUSA_SIN_PERMISO, $usuario, true, null, $existeEnOtraEmpresa,
            'Ni el rol de esta empresa ni una excepción individual conceden este permiso.'
            .($existeEnOtraEmpresa ? ' El permiso sí existe en roles de otra empresa: revisa que la asignación se hiciera en esta empresa.' : ''));
    }

    /** Código de causa estable para las respuestas 403 (sin el detalle). */
    public function causa(Usuario $usuario, string $modulo, string $accion): string
    {
        return $this->diagnosticar($usuario, $modulo, $accion)['causa'];
    }

    /**
     * Pista para el caso «se asignó en otra empresa»: ¿algún rol de otra empresa trae este permiso?
     * No es una causa en sí: la decisión siempre se toma en la empresa de la sesión.
     */
    private function existeEnOtraEmpresa(Usuario $usuario, string $modulo, string $accion): bool
    {
        return Permiso::query()
            ->where('modulo', $modulo)
            ->where('accion', $accion)
            ->whereHas('roles', function ($consulta) use ($usuario) {
                $consulta->where('roles.empresa_id', '!=', $usuario->empresa_id);
            })
            ->exists();
    }

    private function resultado(bool $concedido, string $causa, Usuario $usuario, bool $permisoExiste, ?array $excepcion, bool $existeEnOtraEmpresa, string $detalle): array
    {
        return [
            'concedido' => $concedido,
            'causa' => $causa,
            'empresa_id' => $usuario->empresa_id,
            'rol' => $usuario->rol?->nombre,
            'permiso_existe' => $permisoExiste,
            'excepcion' => $excepcion,
            'existe_en_otra_empresa' => $existeEnOtraEmpresa,
            'detalle' => $detalle,
        ];
    }
}
