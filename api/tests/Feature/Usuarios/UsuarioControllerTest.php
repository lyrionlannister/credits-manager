<?php

namespace Tests\Feature\Usuarios;

use App\Usuarios\Models\Rol;
use App\Usuarios\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class UsuarioControllerTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    private function payloadValido(int $rolId, array $overrides = []): array
    {
        return array_merge([
            'nombre' => 'Usuario de prueba',
            'usuario' => 'usuario_test_'.uniqid(),
            'clave' => 'Clave123!',
            'rol_id' => $rolId,
            'activo' => true,
        ], $overrides);
    }

    private function crearUsuario(Rol $rol, array $overrides = []): Usuario
    {
        return Usuario::create(array_merge([
            'nombre' => 'Usuario existente',
            'usuario' => 'usuario_existente_'.uniqid(),
            'password_hash' => 'irrelevante-en-tests',
            'rol_id' => $rol->id,
            'activo' => true,
        ], $overrides));
    }

    public function test_lista_usuarios_paginados(): void
    {
        $this->actingAsAdmin();

        $respuesta = $this->getJson('/api/v1/usuarios');

        $respuesta->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_sin_autenticar_no_puede_listar(): void
    {
        $respuesta = $this->getJson('/api/v1/usuarios');

        $respuesta->assertStatus(401);
    }

    public function test_asesor_sin_permiso_no_puede_listar(): void
    {
        $this->actingAsAsesor();

        $respuesta = $this->getJson('/api/v1/usuarios');

        $respuesta->assertStatus(403);
    }

    public function test_crea_un_usuario(): void
    {
        $this->actingAsAdmin();
        $rol = Rol::create(['nombre' => 'asesor_test']);

        $respuesta = $this->postJson('/api/v1/usuarios', $this->payloadValido($rol->id, ['usuario' => 'nuevo_usuario_test']));

        $respuesta->assertStatus(201);
        $this->assertDatabaseHas('usuarios', ['usuario' => 'nuevo_usuario_test', 'rol_id' => $rol->id]);
    }

    public function test_rechaza_creacion_con_datos_invalidos(): void
    {
        $this->actingAsAdmin();

        $respuesta = $this->postJson('/api/v1/usuarios', []);

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['nombre', 'usuario', 'clave', 'rol_id', 'activo']);
    }

    public function test_asesor_sin_permiso_no_puede_crear(): void
    {
        $usuario = $this->actingAsAsesor();

        $respuesta = $this->postJson('/api/v1/usuarios', $this->payloadValido($usuario->rol_id));

        $respuesta->assertStatus(403);
    }

    public function test_edita_un_usuario(): void
    {
        $this->actingAsAdmin();
        $rol = Rol::create(['nombre' => 'asesor_test']);
        $usuario = $this->crearUsuario($rol, ['nombre' => 'Nombre viejo']);

        $respuesta = $this->patchJson("/api/v1/usuarios/{$usuario->id}", [
            'nombre' => 'Nombre nuevo',
            'usuario' => $usuario->usuario,
            'rol_id' => $rol->id,
            'activo' => true,
        ]);

        $respuesta->assertStatus(200);
        $this->assertDatabaseHas('usuarios', ['id' => $usuario->id, 'nombre' => 'Nombre nuevo']);
    }

    public function test_admin_restablece_la_clave_de_un_usuario_como_temporal(): void
    {
        $this->actingAsAdmin();
        $rol = Rol::create(['nombre' => 'asesor_test']);
        $usuario = $this->crearUsuario($rol);

        $respuesta = $this->patchJson("/api/v1/usuarios/{$usuario->id}/clave", ['clave' => 'ClaveTemporal123!']);

        $respuesta->assertStatus(204);
        $usuario->refresh();
        $this->assertTrue($usuario->debe_cambiar_clave);
        $this->assertTrue(Hash::check('ClaveTemporal123!', $usuario->password_hash));
    }

    public function test_admin_no_puede_restablecer_su_propia_clave_por_este_endpoint(): void
    {
        $admin = $this->actingAsAdmin();

        $respuesta = $this->patchJson("/api/v1/usuarios/{$admin->id}/clave", ['clave' => 'ClaveTemporal123!']);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('clave');
    }

    public function test_asesor_no_puede_restablecer_claves(): void
    {
        $this->actingAsAsesor();
        $rol = Rol::create(['nombre' => 'asesor_test']);
        $usuario = $this->crearUsuario($rol);

        $respuesta = $this->patchJson("/api/v1/usuarios/{$usuario->id}/clave", ['clave' => 'ClaveTemporal123!']);

        $respuesta->assertStatus(403);
    }

    public function test_usuario_inexistente_devuelve_404(): void
    {
        $this->actingAsAdmin();

        $respuesta = $this->patchJson('/api/v1/usuarios/999999', ['nombre' => 'Cualquiera']);

        $respuesta->assertStatus(404);
    }

    public function test_elimina_un_usuario(): void
    {
        $this->actingAsAdmin();
        $rol = Rol::create(['nombre' => 'asesor_test']);
        $usuario = $this->crearUsuario($rol);

        $respuesta = $this->deleteJson("/api/v1/usuarios/{$usuario->id}");

        $respuesta->assertStatus(204);
        $this->assertSoftDeleted('usuarios', ['id' => $usuario->id]);
    }

    public function test_asesor_sin_permiso_no_puede_eliminar(): void
    {
        $usuario = $this->actingAsAsesor();

        $respuesta = $this->deleteJson("/api/v1/usuarios/{$usuario->id}");

        $respuesta->assertStatus(403);
    }

    public function test_sin_autenticar_no_puede_eliminar(): void
    {
        $respuesta = $this->deleteJson('/api/v1/usuarios/999999');

        $respuesta->assertStatus(401);
    }
}
