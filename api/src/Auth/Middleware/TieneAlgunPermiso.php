<?php

namespace App\Auth\Middleware;

use App\Soporte\Services\AccesoSoporteService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Como `permiso`, para catálogos de consulta que sirven a varias operaciones de módulos distintos: deja pasar a quien
 * tenga al menos uno de los permisos "modulo.accion" dados (`algun_permiso:clientes.crear,metas.configurar`).
 */
class TieneAlgunPermiso
{
    public function handle(Request $peticion, Closure $siguiente, string ...$permisos): Response
    {
        $usuario = $peticion->user();

        // Fase 4: lectura con acceso de soporte activo (ver TienePermiso).
        if ($usuario !== null && $usuario->es_superadmin && $peticion->isMethodSafe()
            && app(AccesoSoporteService::class)->accesoActivo($usuario) !== null) {
            return $siguiente($peticion);
        }

        $autorizado = $usuario !== null && collect($permisos)->contains(function (string $clave) use ($usuario) {
            [$modulo, $accion] = explode('.', $clave, 2);

            return $usuario->tienePermiso($modulo, $accion);
        });

        if (! $autorizado) {
            return response()->json([
                'message' => 'No tienes permiso para realizar esta acción.',
                'motivo_denegacion' => 'sin_permiso',
            ], 403);
        }

        return $siguiente($peticion);
    }
}
