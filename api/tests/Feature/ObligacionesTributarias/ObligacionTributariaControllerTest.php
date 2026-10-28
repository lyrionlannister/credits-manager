<?php

namespace Tests\Feature\ObligacionesTributarias;

use App\Empresas\Models\Empresa;
use App\Usuarios\Models\Rol;
use App\Usuarios\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesObligacionesTributariasFixtures;
use Tests\TestCase;

class ObligacionTributariaControllerTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesObligacionesTributariasFixtures;
    use RefreshDatabase;

    /** `travelTo` no se resetea sola entre tests en esta suite (la app no se destruye entre ellos). */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_crea_una_obligacion(): void
    {
        $admin = $this->actingAsAdmin();
        $tipo = $this->crearTipoObligacion();

        $respuesta = $this->postJson('/api/v1/obligaciones-tributarias', [
            'tipo_obligacion_id' => $tipo->id,
            'periodo' => '2026-10',
            'fecha_limite' => '2026-10-20',
            'responsable_id' => $admin->id,
            'observaciones' => 'Primer período de prueba',
        ]);

        $respuesta->assertStatus(201)->assertJsonPath('obligacion.periodo', '2026-10');
        $this->assertDatabaseHas('obligaciones_tributarias', ['tipo_obligacion_id' => $tipo->id, 'periodo' => '2026-10']);
    }

    public function test_rechaza_el_mismo_tipo_y_periodo_repetido(): void
    {
        $this->actingAsAdmin();
        $tipo = $this->crearTipoObligacion();
        $this->crearObligacion($tipo, ['periodo' => '2026-10']);

        $respuesta = $this->postJson('/api/v1/obligaciones-tributarias', [
            'tipo_obligacion_id' => $tipo->id,
            'periodo' => '2026-10',
            'fecha_limite' => '2026-10-20',
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('tipo_obligacion_id');
    }

    public function test_rechaza_un_responsable_que_no_existe(): void
    {
        $this->actingAsAdmin();
        $tipo = $this->crearTipoObligacion();

        $respuesta = $this->postJson('/api/v1/obligaciones-tributarias', [
            'tipo_obligacion_id' => $tipo->id,
            'periodo' => '2026-10',
            'fecha_limite' => '2026-10-20',
            'responsable_id' => 999999,
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('responsable_id');
    }

    public function test_lista_las_obligaciones_con_el_indicador_de_vencida(): void
    {
        $this->actingAsAdmin();
        $tipo = $this->crearTipoObligacion();
        $this->crearObligacion($tipo, ['periodo' => '2026-01', 'fecha_limite' => '2026-01-10']);
        $this->travelTo(now()->setDate(2026, 10, 12));

        $respuesta = $this->getJson('/api/v1/obligaciones-tributarias');

        $respuesta->assertStatus(200)->assertJsonPath('data.0.vencida', true);
    }

    public function test_sin_autenticar_no_puede_listar(): void
    {
        $this->getJson('/api/v1/obligaciones-tributarias')->assertStatus(401);
    }

    public function test_asesor_sin_permiso_no_puede_crear(): void
    {
        $this->actingAsAsesor();
        $tipo = $this->crearTipoObligacion();

        $respuesta = $this->postJson('/api/v1/obligaciones-tributarias', [
            'tipo_obligacion_id' => $tipo->id,
            'periodo' => '2026-10',
            'fecha_limite' => '2026-10-20',
        ]);

        $respuesta->assertStatus(403);
    }

    public function test_edita_una_obligacion(): void
    {
        $this->actingAsAdmin();
        $tipo = $this->crearTipoObligacion();
        $obligacion = $this->crearObligacion($tipo);

        $respuesta = $this->putJson("/api/v1/obligaciones-tributarias/{$obligacion->id}", [
            'fecha_limite' => '2026-11-05',
            'observaciones' => 'Se corrió la fecha',
        ]);

        $respuesta->assertStatus(200)->assertJsonPath('obligacion.fecha_limite', '2026-11-05');
    }

    public function test_una_obligacion_inexistente_responde_404_al_editar(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/v1/obligaciones-tributarias/999999', ['fecha_limite' => '2026-11-05'])->assertStatus(404);
    }

    public function test_marca_una_obligacion_como_cumplida(): void
    {
        $admin = $this->actingAsAdmin();
        $tipo = $this->crearTipoObligacion();
        $obligacion = $this->crearObligacion($tipo);

        $respuesta = $this->putJson("/api/v1/obligaciones-tributarias/{$obligacion->id}/estado", ['estado' => 'cumplida']);

        $respuesta->assertStatus(200)->assertJsonPath('obligacion.estado', 'cumplida');
        $this->assertDatabaseHas('obligaciones_tributarias', [
            'id' => $obligacion->id,
            'estado' => 'cumplida',
            'cumplida_por' => $admin->id,
        ]);
    }

    public function test_rechaza_un_estado_invalido(): void
    {
        $this->actingAsAdmin();
        $tipo = $this->crearTipoObligacion();
        $obligacion = $this->crearObligacion($tipo);

        $respuesta = $this->putJson("/api/v1/obligaciones-tributarias/{$obligacion->id}/estado", ['estado' => 'vencida']);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('estado');
    }

    public function test_asesor_sin_permiso_no_puede_cambiar_estado(): void
    {
        $admin = $this->actingAsAdmin();
        $tipo = $this->crearTipoObligacion();
        $obligacion = $this->crearObligacion($tipo);
        $this->actingAsAsesor(Empresa::find($admin->empresa_id));

        $this->putJson("/api/v1/obligaciones-tributarias/{$obligacion->id}/estado", ['estado' => 'cumplida'])->assertStatus(403);
    }

    public function test_un_admin_no_ve_obligaciones_de_otra_empresa_en_el_listado(): void
    {
        $this->actingAsAdmin();
        $tipoAjeno = $this->crearTipoObligacion();
        $this->crearObligacion($tipoAjeno);

        $this->actingAsAdmin();
        $tipoPropio = $this->crearTipoObligacion();
        $this->crearObligacion($tipoPropio);

        $respuesta = $this->getJson('/api/v1/obligaciones-tributarias');

        $respuesta->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_un_admin_no_puede_editar_una_obligacion_de_otra_empresa(): void
    {
        $this->actingAsAdmin();
        $tipoAjeno = $this->crearTipoObligacion();
        $obligacionAjena = $this->crearObligacion($tipoAjeno);

        $this->actingAsAdmin();

        $this->putJson("/api/v1/obligaciones-tributarias/{$obligacionAjena->id}", ['fecha_limite' => '2026-12-01'])->assertStatus(404);
    }

    public function test_un_admin_no_puede_cambiar_el_estado_de_una_obligacion_de_otra_empresa(): void
    {
        $this->actingAsAdmin();
        $tipoAjeno = $this->crearTipoObligacion();
        $obligacionAjena = $this->crearObligacion($tipoAjeno);

        $this->actingAsAdmin();

        $this->putJson("/api/v1/obligaciones-tributarias/{$obligacionAjena->id}/estado", ['estado' => 'cumplida'])->assertStatus(404);
    }

    public function test_no_puede_crear_una_obligacion_con_un_tipo_de_otra_empresa(): void
    {
        $this->actingAsAdmin();
        $tipoAjeno = $this->crearTipoObligacion();

        $this->actingAsAdmin();

        $respuesta = $this->postJson('/api/v1/obligaciones-tributarias', [
            'tipo_obligacion_id' => $tipoAjeno->id,
            'periodo' => '2026-10',
            'fecha_limite' => '2026-10-20',
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('tipo_obligacion_id');
    }

    public function test_no_puede_crear_una_obligacion_con_un_responsable_de_otra_empresa(): void
    {
        $adminA = $this->actingAsAdmin();
        $tipo = $this->crearTipoObligacion();

        $adminB = $this->actingAsAdmin();

        $this->actingAs($adminA, 'sanctum');

        $respuesta = $this->postJson('/api/v1/obligaciones-tributarias', [
            'tipo_obligacion_id' => $tipo->id,
            'periodo' => '2026-10',
            'fecha_limite' => '2026-10-20',
            'responsable_id' => $adminB->id,
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('responsable_id');
    }

    public function test_el_color_depende_del_estado_y_de_si_esta_vencida(): void
    {
        $this->actingAsAdmin();
        $tipo = $this->crearTipoObligacion();
        $this->crearObligacion($tipo, ['periodo' => '2026-01', 'fecha_limite' => '2026-01-10', 'estado' => 'pendiente']);
        $this->crearObligacion($tipo, ['periodo' => '2026-12', 'fecha_limite' => '2026-12-31', 'estado' => 'pendiente']);
        $this->crearObligacion($tipo, ['periodo' => '2026-02', 'fecha_limite' => '2026-02-10', 'estado' => 'cumplida']);
        $this->crearObligacion($tipo, ['periodo' => '2026-03', 'fecha_limite' => '2026-03-10', 'estado' => 'no_aplica']);
        $this->travelTo(now()->setDate(2026, 10, 12));

        $respuesta = $this->getJson('/api/v1/obligaciones-tributarias');

        $respuesta->assertStatus(200);
        $colores = collect($respuesta->json('data'))->pluck('color', 'periodo');
        $this->assertSame('danger', $colores['2026-01']);
        $this->assertSame('warning', $colores['2026-12']);
        $this->assertSame('success', $colores['2026-02']);
        $this->assertSame('primary', $colores['2026-03']);
    }

    public function test_filtra_por_mes_de_fecha_limite(): void
    {
        $this->actingAsAdmin();
        $tipo = $this->crearTipoObligacion();
        $this->crearObligacion($tipo, ['periodo' => '2026-10', 'fecha_limite' => '2026-10-15']);
        $this->crearObligacion($tipo, ['periodo' => '2026-11', 'fecha_limite' => '2026-11-05']);

        $respuesta = $this->getJson('/api/v1/obligaciones-tributarias?mes=2026-10');

        $respuesta->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.periodo', '2026-10');
    }

    public function test_rechaza_un_mes_con_formato_invalido(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/obligaciones-tributarias?mes=2026-10-01')->assertStatus(422)->assertJsonValidationErrors('mes');
    }

    public function test_responsables_asignables_incluye_admin_y_contable_pero_no_asesor(): void
    {
        $admin = $this->actingAsAdmin();
        $empresa = Empresa::find($admin->empresa_id);
        $contable = $this->crearUsuarioContable($empresa);
        $this->actingAsAsesor($empresa);
        $this->actingAs($admin, 'sanctum');

        $respuesta = $this->getJson('/api/v1/obligaciones-tributarias/responsables-asignables');

        $respuesta->assertStatus(200);
        $ids = collect($respuesta->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($admin->id));
        $this->assertTrue($ids->contains($contable->id));
        $this->assertCount(2, $ids);
    }

    public function test_sin_autenticar_no_puede_ver_responsables_asignables(): void
    {
        $this->getJson('/api/v1/obligaciones-tributarias/responsables-asignables')->assertStatus(401);
    }

    public function test_un_admin_no_ve_responsables_de_otra_empresa(): void
    {
        $adminA = $this->actingAsAdmin();
        $this->crearUsuarioContable(Empresa::find($adminA->empresa_id));

        $adminB = $this->actingAsAdmin();

        $respuesta = $this->getJson('/api/v1/obligaciones-tributarias/responsables-asignables');

        $respuesta->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $adminB->id);
    }

    private function crearUsuarioContable(Empresa $empresa): Usuario
    {
        $rol = Rol::firstOrCreate(['empresa_id' => $empresa->id, 'nombre' => 'contable']);

        return Usuario::create([
            'empresa_id' => $empresa->id,
            'nombre' => 'Contable de prueba',
            'usuario' => 'contable_'.uniqid(),
            'password_hash' => 'irrelevante-en-tests',
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
    }

    public function test_sin_autenticar_no_puede_eliminar(): void
    {
        $this->deleteJson('/api/v1/obligaciones-tributarias/1')->assertStatus(401);
    }

    public function test_asesor_sin_permiso_no_puede_eliminar(): void
    {
        $admin = $this->actingAsAdmin();
        $obligacion = $this->crearObligacion($this->crearTipoObligacion());
        $this->actingAsAsesor(Empresa::find($admin->empresa_id));

        $this->deleteJson("/api/v1/obligaciones-tributarias/{$obligacion->id}")->assertStatus(403);
    }

    public function test_una_obligacion_inexistente_responde_404_al_eliminar(): void
    {
        $this->actingAsAdmin();

        $this->deleteJson('/api/v1/obligaciones-tributarias/999999')->assertStatus(404);
    }

    public function test_elimina_una_obligacion_pendiente_con_sus_alertas(): void
    {
        $admin = $this->actingAsAdmin();
        $obligacion = $this->crearObligacion($this->crearTipoObligacion());
        $alertaId = DB::table('alertas_obligaciones_tributarias')->insertGetId([
            'empresa_id' => $admin->empresa_id,
            'obligacion_id' => $obligacion->id,
            'fecha_limite' => '2026-10-20',
            'dias_restantes' => 3,
            'generada_en' => now(),
        ]);

        $this->deleteJson("/api/v1/obligaciones-tributarias/{$obligacion->id}")->assertStatus(204);

        $this->assertSoftDeleted('obligaciones_tributarias', ['id' => $obligacion->id], null, 'borrado_en');
        $this->assertSoftDeleted('alertas_obligaciones_tributarias', ['id' => $alertaId], null, 'borrado_en');
    }

    public function test_no_elimina_una_obligacion_cumplida(): void
    {
        $this->actingAsAdmin();
        $obligacion = $this->crearObligacion($this->crearTipoObligacion(), ['estado' => 'cumplida']);

        $this->deleteJson("/api/v1/obligaciones-tributarias/{$obligacion->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('obligacion');
        $this->assertNotSoftDeleted('obligaciones_tributarias', ['id' => $obligacion->id], null, 'borrado_en');
    }

    public function test_una_obligacion_eliminada_se_puede_volver_a_crear_en_el_mismo_periodo(): void
    {
        $this->actingAsAdmin();
        $tipo = $this->crearTipoObligacion();
        $obligacion = $this->crearObligacion($tipo, ['periodo' => '2026-10']);
        $this->deleteJson("/api/v1/obligaciones-tributarias/{$obligacion->id}")->assertStatus(204);

        $this->postJson('/api/v1/obligaciones-tributarias', [
            'tipo_obligacion_id' => $tipo->id,
            'periodo' => '2026-10',
            'fecha_limite' => '2026-10-20',
        ])->assertStatus(201);
    }

    public function test_un_admin_no_puede_eliminar_una_obligacion_de_otra_empresa(): void
    {
        $this->actingAsAdmin();
        $obligacionAjena = $this->crearObligacion($this->crearTipoObligacion());

        $this->actingAsAdmin();

        $this->deleteJson("/api/v1/obligaciones-tributarias/{$obligacionAjena->id}")->assertStatus(404);
        $this->assertNotSoftDeleted('obligaciones_tributarias', ['id' => $obligacionAjena->id], null, 'borrado_en');
    }
}
