<?php

namespace App\Auth\Controllers;

use App\Auth\Requests\CambiarClavePropiaRequest;
use App\Auth\Requests\LoginRequest;
use App\Auth\Services\AuthService;
use App\Http\Controllers\Controller;
use App\Usuarios\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(protected AuthService $autenticacion) {}

    public function login(LoginRequest $peticion): JsonResponse
    {
        $datos = $peticion->validated();

        $this->autenticacion->iniciarSesion($peticion, $datos['usuario'], $datos['clave']);

        return $this->respuestaUsuarioActual($peticion);
    }

    public function logout(Request $peticion): Response
    {
        $this->autenticacion->cerrarSesion($peticion);

        return response()->noContent();
    }

    public function usuarioActual(Request $peticion): JsonResponse
    {
        return $this->respuestaUsuarioActual($peticion);
    }

    /**
     * Fase 3: /me responde siempre con los permisos vigentes y sin caché reutilizable, para que
     * recargar la ventana baste. La versión de permisos permite al cliente detectar el cambio.
     */
    private function respuestaUsuarioActual(Request $peticion): JsonResponse
    {
        // fresh(): el usuario en sesión puede haberse creado antes de que existiera la columna
        // `version_permisos` en el modelo en memoria; sin recargar, el atributo sale null.
        $usuario = $peticion->user()->fresh()->load(['rol.permisos', 'permisosExtra' => Usuario::soloVigentes()]);

        return response()->json([
            'usuario' => $usuario,
            'version_permisos' => $usuario->version_permisos,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function cambiarClave(CambiarClavePropiaRequest $peticion): Response
    {
        $datos = $peticion->validated();

        $this->autenticacion->cambiarClavePropia($peticion->user(), $datos['clave_actual'], $datos['clave_nueva']);

        return response()->noContent();
    }
}
