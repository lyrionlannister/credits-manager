<?php

namespace App\Auth\Middleware;

use App\Usuarios\Services\DiagnosticoPermisoService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Como `permiso`, pero deja pasar también al superadmin (que no tiene rol y por eso
 * `tienePermiso` le da false). El admin de empresa ya pasa: `tienePermiso` le da true
 * sin depender de que el permiso esté asignado. Solo para módulos transversales (auditoría).
 */
class TienePermisoOSuperAdmin
{
    public function handle(Request $peticion, Closure $siguiente, string $modulo, string $accion): Response
    {
        $usuario = $peticion->user();

        if (! $usuario || (! $usuario->es_superadmin && ! $usuario->tienePermiso($modulo, $accion))) {
            return response()->json([
                'message' => 'No tienes permiso para realizar esta acción.',
                'motivo_denegacion' => $usuario
                    ? app(DiagnosticoPermisoService::class)->causa($usuario, $modulo, $accion)
                    : 'sin_sesion',
            ], 403);
        }

        return $siguiente($peticion);
    }
}
