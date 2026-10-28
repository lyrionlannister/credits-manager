<?php

namespace Tests\Feature\ObligacionesTributarias;

use App\Empresas\Models\Empresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesObligacionesTributariasFixtures;
use Tests\TestCase;

class TipoObligacionTributariaControllerTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesObligacionesTributariasFixtures;
    use RefreshDatabase;

    public function test_crea_un_tipo_de_obligacion(): void
    {
        $this->actingAsAdmin();

        $respuesta = $this->postJson('/api/v1/obligaciones-tributarias/tipos', [
            'nombre' => 'IVA bimestral',
            'periodicidad' => 'bimestral',
            'dias_alerta_previa' => 10,
        ]);

        $respuesta->assertStatus(201)->assertJsonPath('tipo.nombre', 'IVA bimestral');
        $this->assertDatabaseHas('tipos_obligacion_tributaria', ['nombre' => 'IVA bimestral', 'periodicidad' => 'bimestral']);
    }

    public function test_rechaza_nombre_duplicado_en_la_misma_empresa(): void
    {
        $this->actingAsAdmin();
        $this->crearTipoObligacion(['nombre' => 'RteICA']);

        $respuesta = $this->postJson('/api/v1/obligaciones-tributarias/tipos', [
            'nombre' => 'RteICA',
            'periodicidad' => 'mensual',
            'dias_alerta_previa' => 5,
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('nombre');
    }

    public function test_rechaza_periodicidad_invalida(): void
    {
        $this->actingAsAdmin();

        $respuesta = $this->postJson('/api/v1/obligaciones-tributarias/tipos', [
            'nombre' => 'RteICA',
            'periodicidad' => 'quincenal',
            'dias_alerta_previa' => 5,
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('periodicidad');
    }

    public function test_lista_los_tipos_de_obligacion(): void
    {
        $this->actingAsAdmin();
        $this->crearTipoObligacion();
        $this->crearTipoObligacion();

        $respuesta = $this->getJson('/api/v1/obligaciones-tributarias/tipos');

        $respuesta->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_sin_autenticar_no_puede_listar(): void
    {
        $this->getJson('/api/v1/obligaciones-tributarias/tipos')->assertStatus(401);
    }

    public function test_asesor_sin_permiso_no_puede_listar(): void
    {
        $this->actingAsAsesor();

        $this->getJson('/api/v1/obligaciones-tributarias/tipos')->assertStatus(403);
    }

    public function test_asesor_sin_permiso_no_puede_crear(): void
    {
        $this->actingAsAsesor();

        $respuesta = $this->postJson('/api/v1/obligaciones-tributarias/tipos', [
            'nombre' => 'RteICA',
            'periodicidad' => 'mensual',
            'dias_alerta_previa' => 5,
        ]);

        $respuesta->assertStatus(403);
    }

    public function test_edita_un_tipo_de_obligacion(): void
    {
        $this->actingAsAdmin();
        $tipo = $this->crearTipoObligacion(['nombre' => 'RteICA']);

        $respuesta = $this->putJson("/api/v1/obligaciones-tributarias/tipos/{$tipo->id}", [
            'nombre' => 'RteICA mensual',
            'periodicidad' => 'mensual',
            'dias_alerta_previa' => 7,
            'activo' => false,
        ]);

        $respuesta->assertStatus(200)->assertJsonPath('tipo.activo', false);
    }

    public function test_un_tipo_de_obligacion_inexistente_responde_404_al_editar(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/v1/obligaciones-tributarias/tipos/999999', [
            'nombre' => 'X', 'periodicidad' => 'mensual', 'dias_alerta_previa' => 5, 'activo' => true,
        ])->assertStatus(404);
    }

    public function test_un_admin_no_puede_editar_un_tipo_de_otra_empresa(): void
    {
        $this->actingAsAdmin();
        $tipoAjeno = $this->crearTipoObligacion();

        $this->actingAsAdmin();

        $respuesta = $this->putJson("/api/v1/obligaciones-tributarias/tipos/{$tipoAjeno->id}", [
            'nombre' => 'Intento ajeno', 'periodicidad' => 'mensual', 'dias_alerta_previa' => 5, 'activo' => true,
        ]);

        $respuesta->assertStatus(404);
    }

    public function test_un_admin_no_ve_tipos_de_otra_empresa_en_el_listado(): void
    {
        $this->actingAsAdmin();
        $this->crearTipoObligacion();

        $this->actingAsAdmin();
        $this->crearTipoObligacion();

        $respuesta = $this->getJson('/api/v1/obligaciones-tributarias/tipos');

        $respuesta->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_sin_autenticar_no_puede_eliminar_un_tipo(): void
    {
        $this->deleteJson('/api/v1/obligaciones-tributarias/tipos/1')->assertStatus(401);
    }

    public function test_asesor_sin_permiso_no_puede_eliminar_un_tipo(): void
    {
        $admin = $this->actingAsAdmin();
        $tipo = $this->crearTipoObligacion();
        $this->actingAsAsesor(Empresa::find($admin->empresa_id));

        $this->deleteJson("/api/v1/obligaciones-tributarias/tipos/{$tipo->id}")->assertStatus(403);
    }

    public function test_un_tipo_inexistente_responde_404_al_eliminar(): void
    {
        $this->actingAsAdmin();

        $this->deleteJson('/api/v1/obligaciones-tributarias/tipos/999999')->assertStatus(404);
    }

    public function test_elimina_un_tipo_sin_obligaciones_y_permite_reusar_el_nombre(): void
    {
        $this->actingAsAdmin();
        $tipo = $this->crearTipoObligacion(['nombre' => 'ICA anual']);

        $this->deleteJson("/api/v1/obligaciones-tributarias/tipos/{$tipo->id}")->assertStatus(204);
        $this->assertSoftDeleted('tipos_obligacion_tributaria', ['id' => $tipo->id], null, 'borrado_en');

        $this->postJson('/api/v1/obligaciones-tributarias/tipos', [
            'nombre' => 'ICA anual',
            'periodicidad' => 'anual',
            'dias_alerta_previa' => 5,
        ])->assertStatus(201);
    }

    public function test_no_elimina_un_tipo_con_obligaciones(): void
    {
        $this->actingAsAdmin();
        $tipo = $this->crearTipoObligacion();
        $this->crearObligacion($tipo);

        $this->deleteJson("/api/v1/obligaciones-tributarias/tipos/{$tipo->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo');
        $this->assertNotSoftDeleted('tipos_obligacion_tributaria', ['id' => $tipo->id], null, 'borrado_en');
    }

    public function test_un_admin_no_puede_eliminar_un_tipo_de_otra_empresa(): void
    {
        $this->actingAsAdmin();
        $tipoAjeno = $this->crearTipoObligacion();

        $this->actingAsAdmin();

        $this->deleteJson("/api/v1/obligaciones-tributarias/tipos/{$tipoAjeno->id}")->assertStatus(404);
        $this->assertNotSoftDeleted('tipos_obligacion_tributaria', ['id' => $tipoAjeno->id], null, 'borrado_en');
    }
}
