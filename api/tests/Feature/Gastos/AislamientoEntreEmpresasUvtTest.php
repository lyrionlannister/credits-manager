<?php

namespace Tests\Feature\Gastos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesGastosFixtures;
use Tests\TestCase;

/**
 * La UVT era una constante global (sin `empresa_id`): un admin de cualquier empresa podía crear, editar o
 * eliminar el valor que usaban todas las demás. Prueba que ahora cada empresa tiene los suyos, aislados.
 */
class AislamientoEntreEmpresasUvtTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesGastosFixtures;
    use RefreshDatabase;

    public function test_un_admin_no_ve_valores_uvt_de_otra_empresa_en_el_listado(): void
    {
        $this->actingAsAdmin();
        $this->crearValorUvt(2027, 47065);

        $this->actingAsAdmin();
        $this->crearValorUvt(2028, 49799);

        $respuesta = $this->getJson('/api/v1/gastos/valores-uvt');

        $respuesta->assertStatus(200);
        $this->assertCount(1, $respuesta->json('valores_uvt'));
        $this->assertSame('2028-01-01', $respuesta->json('valores_uvt.0.fecha_desde'));
    }

    public function test_un_admin_no_puede_editar_un_valor_uvt_de_otra_empresa(): void
    {
        $this->actingAsAdmin();
        $valorAjeno = $this->crearValorUvt(2027, 47065);

        $this->actingAsAdmin();

        $respuesta = $this->patchJson("/api/v1/gastos/valores-uvt/{$valorAjeno->id}", ['valor' => 99999]);

        $respuesta->assertStatus(404);
        $this->assertDatabaseHas('valores_uvt', ['id' => $valorAjeno->id, 'valor' => 4706500]);
    }

    public function test_un_admin_no_puede_eliminar_un_valor_uvt_de_otra_empresa(): void
    {
        $this->actingAsAdmin();
        $valorAjeno = $this->crearValorUvt(2027, 47065);

        $this->actingAsAdmin();

        $respuesta = $this->deleteJson("/api/v1/gastos/valores-uvt/{$valorAjeno->id}");

        $respuesta->assertStatus(404);
        $this->assertDatabaseHas('valores_uvt', ['id' => $valorAjeno->id, 'borrado_en' => null]);
    }

    public function test_dos_empresas_registran_su_propio_valor_desde_la_misma_fecha(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/gastos/valores-uvt', ['fecha_desde' => '2027-01-01', 'valor' => 47065])->assertStatus(201);

        $this->actingAsAdmin();
        $respuesta = $this->postJson('/api/v1/gastos/valores-uvt', ['fecha_desde' => '2027-01-01', 'valor' => 49799]);

        $respuesta->assertStatus(201);
        $this->assertDatabaseCount('valores_uvt', 2);
    }
}
