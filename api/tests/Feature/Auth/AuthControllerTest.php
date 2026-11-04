<?php

namespace Tests\Feature\Auth;

use App\Usuarios\Models\Rol;
use App\Usuarios\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    private function crearUsuarioConClave(string $clave): Usuario
    {
        $empresa = $this->crearEmpresaDePrueba();
        $rol = Rol::create(['empresa_id' => $empresa->id, 'nombre' => 'admin']);

        return Usuario::create([
            'empresa_id' => $empresa->id,
            'nombre' => 'Usuario de prueba',
            'usuario' => 'login_test_'.uniqid(),
            'password_hash' => $clave,
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
    }

    private function loginDesdeFrontend(array $datos): TestResponse
    {
        $referer = 'https://'.collect(config('sanctum.stateful'))->first();

        return $this->withHeader('Referer', $referer)->postJson('/api/v1/login', $datos);
    }

    public function test_login_exitoso_con_credenciales_validas(): void
    {
        $usuario = $this->crearUsuarioConClave('Clave123!');

        $respuesta = $this->loginDesdeFrontend(['usuario' => $usuario->usuario, 'clave' => 'Clave123!']);

        $respuesta->assertStatus(200)->assertJsonPath('usuario.usuario', $usuario->usuario);
    }

    public function test_rechaza_login_con_clave_incorrecta(): void
    {
        $usuario = $this->crearUsuarioConClave('Clave123!');

        $respuesta = $this->loginDesdeFrontend(['usuario' => $usuario->usuario, 'clave' => 'ClaveIncorrecta1!']);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('usuario');
    }

    public function test_login_exitoso_estando_ya_autenticado_como_usuario_de_otra_empresa(): void
    {
        $usuarioA = $this->crearUsuarioConClave('Clave123!');
        $usuarioB = $this->crearUsuarioConClave('OtraClave456!');

        $this->loginDesdeFrontend(['usuario' => $usuarioA->usuario, 'clave' => 'Clave123!'])->assertStatus(200);

        $respuesta = $this->loginDesdeFrontend(['usuario' => $usuarioB->usuario, 'clave' => 'OtraClave456!']);

        $respuesta->assertStatus(200)->assertJsonPath('usuario.usuario', $usuarioB->usuario);
    }

    public function test_rechaza_login_con_datos_faltantes(): void
    {
        $respuesta = $this->loginDesdeFrontend([]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['usuario', 'clave']);
    }

    public function test_cierra_sesion(): void
    {
        $this->actingAsAdmin();
        $referer = 'https://'.collect(config('sanctum.stateful'))->first();

        $respuesta = $this->withHeader('Referer', $referer)->postJson('/api/v1/logout');

        $respuesta->assertStatus(204);
    }

    public function test_sin_autenticar_no_puede_cerrar_sesion(): void
    {
        $respuesta = $this->postJson('/api/v1/logout');

        $respuesta->assertStatus(401);
    }

    public function test_cambia_la_clave_propia(): void
    {
        $usuario = $this->crearUsuarioConClave('ClaveVieja123!');
        $this->actingAs($usuario, 'sanctum');

        $respuesta = $this->patchJson('/api/v1/me/clave', [
            'clave_actual' => 'ClaveVieja123!',
            'clave_nueva' => 'ClaveNueva123!',
        ]);

        $respuesta->assertStatus(204);
        $this->assertTrue(Hash::check('ClaveNueva123!', $usuario->fresh()->password_hash));
    }

    public function test_cambiar_la_clave_propia_quita_la_marca_de_clave_temporal(): void
    {
        $usuario = $this->crearUsuarioConClave('ClaveVieja123!');
        $usuario->update(['debe_cambiar_clave' => true]);
        $this->actingAs($usuario, 'sanctum');

        $respuesta = $this->patchJson('/api/v1/me/clave', [
            'clave_actual' => 'ClaveVieja123!',
            'clave_nueva' => 'ClaveNueva123!',
        ]);

        $respuesta->assertStatus(204);
        $this->assertFalse($usuario->fresh()->debe_cambiar_clave);
    }

    public function test_usuario_con_clave_temporal_no_puede_usar_otras_rutas(): void
    {
        $usuario = $this->crearUsuarioConClave('ClaveVieja123!');
        $usuario->update(['debe_cambiar_clave' => true]);
        $this->actingAs($usuario, 'sanctum');

        $respuesta = $this->getJson('/api/v1/empresa');

        $respuesta->assertStatus(403);
    }

    public function test_usuario_con_clave_temporal_puede_consultar_su_perfil_y_salir(): void
    {
        $usuario = $this->crearUsuarioConClave('ClaveVieja123!');
        $usuario->update(['debe_cambiar_clave' => true]);
        $this->actingAs($usuario, 'sanctum');

        $referer = 'https://'.collect(config('sanctum.stateful'))->first();

        $this->getJson('/api/v1/me')->assertStatus(200);
        $this->withHeader('Referer', $referer)->postJson('/api/v1/logout')->assertStatus(204);
    }

    public function test_rechaza_cambio_de_clave_con_clave_actual_incorrecta(): void
    {
        $usuario = $this->crearUsuarioConClave('ClaveVieja123!');
        $this->actingAs($usuario, 'sanctum');

        $respuesta = $this->patchJson('/api/v1/me/clave', [
            'clave_actual' => 'Incorrecta123!',
            'clave_nueva' => 'ClaveNueva123!',
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('clave_actual');
    }

    public function test_rechaza_clave_nueva_debil(): void
    {
        $usuario = $this->crearUsuarioConClave('ClaveVieja123!');
        $this->actingAs($usuario, 'sanctum');

        $respuesta = $this->patchJson('/api/v1/me/clave', [
            'clave_actual' => 'ClaveVieja123!',
            'clave_nueva' => 'abc',
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('clave_nueva');
    }

    public function test_sin_autenticar_no_puede_cambiar_clave(): void
    {
        $respuesta = $this->patchJson('/api/v1/me/clave', [
            'clave_actual' => 'irrelevante',
            'clave_nueva' => 'irrelevante',
        ]);

        $respuesta->assertStatus(401);
    }
}
