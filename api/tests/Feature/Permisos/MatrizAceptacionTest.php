<?php

namespace Tests\Feature\Permisos;

use App\Usuarios\Models\Permiso;
use App\Usuarios\Models\Rol;
use App\Usuarios\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesAuditoriaFixtures;
use Tests\TestCase;

/**
 * Fase 8: matriz de aceptación A–L (recargar una vez y debe funcionar; sin cerrar sesión).
 * Los casos D, E, F, G, I, J, L, M, N, O, P, Q y R viven en sus tests de fase; aquí van los
 * que faltaban como flujo de recarga: A (añadir al rol), B (retirar del rol), C (excepción
 * concedida sin rol) y H (debe_cambiar_clave no es falta de permiso).
 */
class MatrizAceptacionTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesAuditoriaFixtures;
    use RefreshDatabase;

    private Usuario $usuario;

    private Usuario $admin;

    private Rol $rol;

    protected function setUp(): void
    {
        parent::setUp();

        // Un solo admin por test: cada actingAsAdmin() crea una empresa nueva y el scope
        // de empresa haría 404 al tocar el rol de la primera.
        $this->admin = $this->actingAsAdmin();
        $this->rol = Rol::create(['empresa_id' => $this->admin->empresa_id, 'nombre' => 'asesor_test_'.uniqid()]);
        $this->usuario = Usuario::create([
            'empresa_id' => $this->admin->empresa_id,
            'nombre' => 'Usuario de prueba',
            'usuario' => 'usuario_test_'.uniqid(),
            'password_hash' => 'irrelevante-en-tests',
            'rol_id' => $this->rol->id,
            'activo' => true,
        ]);
    }

    private function comoUsuario(): void
    {
        $this->actingAs($this->usuario, 'sanctum');
    }

    private function comoAdmin(): void
    {
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_caso_a_permiso_anadido_al_rol_aparece_al_recargar(): void
    {
        $this->comoUsuario();
        $this->getJson('/api/v1/empleadores')->assertForbidden();

        $this->comoAdmin();
        $permiso = Permiso::create(['modulo' => 'empleadores', 'accion' => 'listar']);
        $this->patchJson("/api/v1/roles/{$this->rol->id}", [
            'nombre' => $this->rol->nombre, 'permisos' => [$permiso->id],
        ])->assertOk();

        // Recarga: /me fresco trae el permiso y la ruta ya responde.
        $this->comoUsuario();
        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonFragment(['modulo' => 'empleadores', 'accion' => 'listar']);
        $this->getJson('/api/v1/empleadores')->assertOk();
    }

    public function test_caso_b_permiso_retirado_del_rol_deniega_al_recargar(): void
    {
        $permiso = Permiso::create(['modulo' => 'empleadores', 'accion' => 'listar']);
        $this->rol->permisos()->attach($permiso->id);

        $this->comoUsuario();
        $this->getJson('/api/v1/empleadores')->assertOk();

        $this->comoAdmin();
        $this->patchJson("/api/v1/roles/{$this->rol->id}", [
            'nombre' => $this->rol->nombre, 'permisos' => [],
        ])->assertOk();

        $this->comoUsuario();
        $this->getJson('/api/v1/empleadores')->assertForbidden()
            ->assertJsonPath('motivo_denegacion', 'sin_permiso');
    }

    public function test_caso_c_excepcion_concedida_sin_rol_funciona_hasta_su_vencimiento(): void
    {
        $permiso = Permiso::create(['modulo' => 'empleadores', 'accion' => 'listar']);

        $this->comoAdmin();
        $this->patchJson("/api/v1/usuarios/{$this->usuario->id}", [
            'nombre' => $this->usuario->nombre, 'usuario' => $this->usuario->usuario,
            'rol_id' => $this->rol->id, 'activo' => true,
            'permisos_extra' => [['permiso_id' => $permiso->id, 'concedido' => true, 'motivo' => 'Suplencia']],
        ])->assertOk();

        $this->comoUsuario();
        $this->getJson('/api/v1/empleadores')->assertOk();

        // Al vencer, vuelve a mandar el rol (que no lo tiene): deniega sin más cambios.
        $this->usuario->permisosExtra()->updateExistingPivot($permiso->id, ['vence_en' => now()->subDay()]);
        \Illuminate\Support\Facades\Artisan::call('permisos:vencer-excepciones');

        $this->getJson('/api/v1/empleadores')->assertForbidden();
    }

    public function test_caso_h_debe_cambiar_clave_no_es_falta_de_permiso(): void
    {
        $this->usuario->update(['debe_cambiar_clave' => true]);

        $this->comoUsuario();
        $this->getJson('/api/v1/empleadores')->assertForbidden()
            ->assertJsonFragment(['message' => 'Debes cambiar tu contraseña temporal antes de continuar.']);
        $this->getJson('/api/v1/me')->assertOk();
    }
}
