<?php

namespace App\Auth\Middleware;

use App\Soporte\Services\AccesoSoporteService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El acceso de soporte just-in-time es solo de lectura: con un acceso activo, el superadmin
 * no puede escribir nada (ni exportar) salvo cerrar su sesión y gestionar sus propios accesos.
 * Las lecturas (GET/HEAD/OPTIONS) pasan; el permiso concreto de cada lectura lo decide `permiso`.
 */
class SoloLecturaSoporte
{
    public function handle(Request $peticion, Closure $siguiente): Response
    {
        $usuario = $peticion->user();

        if ($usuario !== null && $usuario->es_superadmin && $peticion->method() !== 'GET' && $peticion->method() !== 'HEAD' && $peticion->method() !== 'OPTIONS') {
            if (app(AccesoSoporteService::class)->accesoActivo($usuario) !== null) {
                if ($peticion->is('api/v1/logout') || $peticion->is('api/v1/accesos-soporte*')) {
                    return $siguiente($peticion);
                }

                return response()->json([
                    'message' => 'El acceso de soporte es solo de lectura.',
                    'motivo_denegacion' => 'acceso_soporte_solo_lectura',
                ], 403);
            }
        }

        return $siguiente($peticion);
    }
}
