<?php

namespace App\Auth\Middleware;

use App\Auth\Controllers\AuthController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DebeCambiarClave
{
    /** Únicas rutas que un usuario con clave temporal puede usar hasta que la cambie. */
    private const RUTAS_PERMITIDAS = [
        AuthController::class.'@logout',
        AuthController::class.'@usuarioActual',
        AuthController::class.'@cambiarClave',
    ];

    public function handle(Request $peticion, Closure $siguiente): Response
    {
        $usuario = $peticion->user();

        if ($usuario && $usuario->debe_cambiar_clave && ! in_array($peticion->route()?->getActionName(), self::RUTAS_PERMITIDAS, true)) {
            abort(403, 'Debes cambiar tu contraseña temporal antes de continuar.');
        }

        return $siguiente($peticion);
    }
}
