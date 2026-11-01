<?php

namespace App\Auth\Services;

use App\Auditoria\Enums\AccionAuditoria;
use App\Auditoria\Services\EmisorAuditoria;
use App\Auth\Enums\MotivoLoginFallido;
use App\Usuarios\Models\Usuario;
use App\Usuarios\Repositories\UsuarioRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        protected UsuarioRepository $usuarios,
        protected EmisorAuditoria $auditoria,
    ) {}

    public function iniciarSesion(Request $peticion, string $usuario, string $clave): void
    {
        // Se cierra antes de autenticar: el scope de empresa de la sesión vigente ocultaría al usuario que intenta entrar.
        if ($sesionPrevia = Auth::user()) {
            $this->auditoria->registrar(AccionAuditoria::Logout, 'auth', 'Cierre de sesión de '.$sesionPrevia->nombre.' por un nuevo intento de inicio de sesión', 'usuario', $sesionPrevia->id);
            Auth::guard('web')->logout();
        }

        $autenticado = Auth::attempt([
            'usuario' => strtolower($usuario),
            'password' => $clave,
            'activo' => true,
        ]);

        if (! $autenticado) {
            $this->registrarLoginFallido($usuario, $clave);

            throw ValidationException::withMessages([
                'usuario' => __('validation.custom.usuario.credenciales_invalidas'),
            ]);
        }

        $peticion->session()->regenerate();

        $this->auditoria->registrar(AccionAuditoria::Login, 'auth', 'Inicio de sesión de '.Auth::user()->nombre, 'usuario', Auth::id());
    }

    public function cerrarSesion(Request $peticion): void
    {
        $this->auditoria->registrar(AccionAuditoria::Logout, 'auth', 'Cierre de sesión de '.$peticion->user()->nombre, 'usuario', $peticion->user()->id);

        Auth::guard('web')->logout();

        $peticion->session()->invalidate();
        $peticion->session()->regenerateToken();
    }

    public function cambiarClavePropia(Usuario $usuario, string $claveActual, string $claveNueva): void
    {
        if (! Hash::check($claveActual, $usuario->password_hash)) {
            throw ValidationException::withMessages([
                'clave_actual' => __('validation.custom.clave_actual.incorrecta'),
            ]);
        }

        $this->usuarios->actualizar($usuario, ['password_hash' => $claveNueva, 'debe_cambiar_clave' => false]);

        $this->auditoria->registrar(AccionAuditoria::Modificar, 'auth', 'Cambio de la contraseña propia de '.$usuario->usuario, 'usuario', $usuario->id);
    }

    /** El cliente sigue viendo un error genérico; la razón real solo queda en la bitácora. Nunca se guarda la clave digitada. */
    private function registrarLoginFallido(string $usuario, string $clave): void
    {
        $usuario = strtolower($usuario);
        $existente = $this->usuarios->buscarPorUsuario($usuario);

        // `attempt` ya exigió `activo`: un usuario activo que llegó aquí solo pudo fallar por la clave, sin volver a hashear.
        $motivo = match (true) {
            $existente === null => MotivoLoginFallido::UsuarioInexistente,
            $existente->trashed() => MotivoLoginFallido::UsuarioDadoDeBaja,
            $existente->activo || ! Hash::check($clave, $existente->password_hash) => MotivoLoginFallido::ClaveIncorrecta,
            default => MotivoLoginFallido::UsuarioInactivo,
        };

        // Sin usuario persistido, el snapshot del autor lleva solo el nombre digitado, recortado.
        $autor = $existente ?? new Usuario(['usuario' => mb_substr($usuario, 0, 60)]);

        $this->auditoria->registrar(
            AccionAuditoria::LoginFallido,
            'auth',
            'Intento fallido de inicio de sesión de '.$autor->usuario,
            $existente ? 'usuario' : null,
            $existente?->id,
            motivo: $motivo->descripcion(),
            autor: $autor,
            empresaId: $existente?->empresa_id,
        );
    }
}
