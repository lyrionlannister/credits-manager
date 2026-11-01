<?php

namespace Tests\Feature\Seguridad;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class AdminEmpresaControllerTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_sin_autenticar_responde_401(): void
    {
        $this->getJson('/api/v1/seguridad/admins-empresa')->assertUnauthorized();
    }

    public function test_el_administrador_de_una_empresa_no_puede_listar(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/seguridad/admins-empresa')->assertForbidden();
    }

    public function test_lista_los_admins_de_todas_las_empresas(): void
    {
        $adminUno = $this->actingAsAdmin();
        $adminDos = $this->actingAsAdmin();
        $this->actingAsAsesor(); // no es admin: no debe aparecer

        $this->actingAsSuperAdmin();

        $respuesta = $this->getJson('/api/v1/seguridad/admins-empresa')->assertOk();

        $this->assertSame(
            [$adminUno->id, $adminDos->id],
            collect($respuesta->json('admins'))->pluck('id')->sort()->values()->all(),
        );
    }

    public function test_restablece_la_clave_de_un_admin_como_temporal(): void
    {
        $admin = $this->actingAsAdmin();
        $this->actingAsSuperAdmin();

        $respuesta = $this->patchJson("/api/v1/seguridad/admins-empresa/{$admin->id}/clave")->assertOk();

        $claveTemporal = $respuesta->json('clave_temporal');
        $this->assertNotEmpty($claveTemporal);

        $admin->refresh();
        $this->assertTrue($admin->debe_cambiar_clave);
        $this->assertTrue(Hash::check($claveTemporal, $admin->password_hash));
    }

    public function test_el_administrador_de_una_empresa_no_puede_resetear_claves(): void
    {
        $admin = $this->actingAsAdmin();

        $this->patchJson("/api/v1/seguridad/admins-empresa/{$admin->id}/clave")->assertForbidden();
    }

    public function test_un_usuario_que_no_es_admin_devuelve_404(): void
    {
        $usuario = $this->actingAsAsesor();
        $this->actingAsSuperAdmin();

        $this->patchJson("/api/v1/seguridad/admins-empresa/{$usuario->id}/clave")->assertNotFound();
    }
}
