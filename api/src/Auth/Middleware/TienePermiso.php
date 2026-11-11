<?php

namespace App\Auth\Middleware;

use App\Soporte\Services\AccesoSoporteService;
use App\Usuarios\Services\DiagnosticoPermisoService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TienePermiso
{
    public function handle(Request $peticion, Closure $siguiente, string $modulo, string $accion): Response
    {
        $usuario = $peticion->user();

        // Fase 4: el superadmin con acceso de soporte activo puede LEER (GET/HEAD) los datos de
        // esa empresa; escribir sigue denegado por SoloLecturaSoporte y por este mismo middleware.
        if ($usuario !== null && $usuario->es_superadmin && $peticion->isMethodSafe()
            && app(AccesoSoporteService::class)->accesoActivo($usuario) !== null) {
            return $siguiente($peticion);
        }

        if (! $usuario || ! $usuario->tienePermiso($modulo, $accion)) {
            // El mensaje al usuario final sigue siendo genérico; la causa estable viaja en
            // `motivo_denegacion` para que la interfaz y el diagnóstico la expliquen sin adivinar.
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
