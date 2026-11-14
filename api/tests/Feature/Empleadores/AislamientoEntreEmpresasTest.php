<?php

namespace Tests\Feature\Empleadores;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

/**
 * Prueba que EmpresaScope (App\Core\Concerns\PerteneceAEmpresa) realmente
 * aísla los datos: sin esto, el resto del feature multiempresa son columnas
 * `empresa_id` sin ningún efecto.
 */
class AislamientoEntreEmpresasTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    public function test_un_admin_no_ve_empleadores_de_otra_empresa_en_el_listado(): void
    {
        $this->actingAsAdmin();
        $this->crearEmpleador(['nombre' => 'Empleador de la empresa A']);

        $this->actingAsAdmin();
        $this->crearEmpleador(['nombre' => 'Empleador de la empresa B']);

        $respuesta = $this->getJson('/api/v1/empleadores');

        $respuesta->assertStatus(200);
        $this->assertCount(1, $respuesta->json('data'));
        $this->assertSame('Empleador de la empresa B', $respuesta->json('data.0.nombre'));
    }

    public function test_un_admin_no_puede_acceder_a_un_empleador_de_otra_empresa_por_id(): void
    {
        $this->actingAsAdmin();
        $empleadorAjeno = $this->crearEmpleador();

        $this->actingAsAdmin();

        $respuesta = $this->getJson("/api/v1/empleadores/{$empleadorAjeno->id}");

        $respuesta->assertStatus(404);
    }

    public function test_un_admin_no_puede_editar_un_empleador_de_otra_empresa(): void
    {
        $this->actingAsAdmin();
        $empleadorAjeno = $this->crearEmpleador();

        $this->actingAsAdmin();

        $respuesta = $this->patchJson("/api/v1/empleadores/{$empleadorAjeno->id}", [
            'nombre' => 'Intento de edición ajena',
        ]);

        $respuesta->assertStatus(404);
    }

    public function test_un_admin_no_puede_eliminar_un_empleador_de_otra_empresa(): void
    {
        $this->actingAsAdmin();
        $empleadorAjeno = $this->crearEmpleador();

        $this->actingAsAdmin();

        $respuesta = $this->deleteJson("/api/v1/empleadores/{$empleadorAjeno->id}");

        $respuesta->assertStatus(404);
        $this->assertDatabaseHas('empleadores', ['id' => $empleadorAjeno->id, 'borrado_en' => null]);
    }
}
