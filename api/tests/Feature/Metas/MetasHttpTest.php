<?php

namespace Tests\Feature\Metas;

use App\Empresas\Models\Empresa;
use App\Metas\Enums\TipoMeta;
use App\Metas\Models\ConfiguracionMeta;
use App\Metas\Models\Meta;
use App\Metas\Models\MetaAsesor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\AfirmaAuditoria;
use Tests\Concerns\CreatesMetasFixtures;
use Tests\TestCase;

class MetasHttpTest extends TestCase
{
    use ActsAsAdmin;
    use AfirmaAuditoria;
    use CreatesMetasFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function payloadConfiguracion(array $cambios = []): array
    {
        return array_merge([
            'tolerancia_riesgo_puntos' => 10,
            'cumplimiento_parcial_minimo' => 90,
            'alerta_alcanzada_activa' => true,
            'umbral_alcanzada' => 100,
            'modo_colocacion_refinanciaciones' => 'solo_dinero_nuevo',
            'base_recaudo' => 'total',
            'distribucion_obligatoria_completa' => false,
            'meta_consolidada_visible_asesor' => false,
            'dia_de_cierre' => 'ultimo_dia',
        ], $cambios);
    }

    public function test_sin_sesion_responde_401(): void
    {
        $this->actingAsAdmin();
        $this->salirDeSesion();

        $this->getJson('/api/v1/metas')->assertUnauthorized();
        $this->getJson('/api/v1/metas/configuracion')->assertUnauthorized();
    }

    public function test_asesor_sin_meta_consolidada_no_lista_metas(): void
    {
        $this->actingAsAsesor();

        $this->getJson('/api/v1/metas')->assertForbidden();
        $this->getJson('/api/v1/metas/alertas')->assertForbidden();
    }

    public function test_asesor_no_puede_crear_ni_configurar_metas(): void
    {
        $this->actingAsAsesor();

        $this->postJson('/api/v1/metas', ['periodo' => '2026-10', 'tipo' => 'colocacion', 'valor_objetivo' => 100])->assertForbidden();
        $this->putJson('/api/v1/metas/configuracion', $this->payloadConfiguracion())->assertForbidden();
    }

    public function test_crear_meta_valida_periodo_y_no_repite_tipo_del_mismo_periodo(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/metas', ['periodo' => '2026-13', 'tipo' => 'colocacion', 'valor_objetivo' => 100])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['periodo']);

        $this->postJson('/api/v1/metas', ['periodo' => '2026-10', 'tipo' => 'colocacion', 'valor_objetivo' => 1000000])->assertCreated();

        $this->postJson('/api/v1/metas', ['periodo' => '2026-10', 'tipo' => 'colocacion', 'valor_objetivo' => 2000000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tipo']);
    }

    public function test_crear_meta_guarda_en_base_de_datos_y_registra_en_bitacora(): void
    {
        $this->actingAsAdmin();

        $respuesta = $this->postJson('/api/v1/metas', ['periodo' => '2026-10', 'tipo' => 'recaudo', 'valor_objetivo' => 1500000])
            ->assertCreated();

        $meta = Meta::query()->findOrFail($respuesta->json('meta.id'));
        $this->assertSame('recaudo', $meta->tipo->value);
        $this->assertSame('1500000.00', $meta->valor_objetivo->aDecimalString());
        $this->assertEventoAuditoria('crear', 'metas', 'meta', $meta->id);
    }

    public function test_editar_meta_registra_valor_anterior_y_nuevo(): void
    {
        $this->actingAsAdmin();
        $meta = $this->crearMeta('2026-10', TipoMeta::Colocacion, 1000000);

        $this->putJson("/api/v1/metas/{$meta->id}", ['valor_objetivo' => 2000000])->assertOk();

        $evento = $this->assertEventoAuditoria('modificar', 'metas', 'meta', $meta->id);
        $this->assertSame('1000000.00', $evento['valores_anteriores']['valor_objetivo']);
        $this->assertSame('2000000.00', $evento['valores_nuevos']['valor_objetivo']);
        $this->assertSame('2000000.00', $meta->fresh()->valor_objetivo->aDecimalString());
    }

    public function test_distribuir_suma_mayor_a_la_meta_responde_422(): void
    {
        $admin = $this->actingAsAdmin();
        $meta = $this->crearMeta('2026-10', TipoMeta::Colocacion, 1000000);
        $ana = $this->crearAsesor(Empresa::findOrFail($admin->empresa_id));

        $this->putJson("/api/v1/metas/{$meta->id}/asesores", ['asesores' => [
            ['usuario_id' => $ana->id, 'valor_objetivo' => 600000],
            ['usuario_id' => $this->crearAsesor(Empresa::findOrFail($admin->empresa_id))->id, 'valor_objetivo' => 500000],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['asesores']);
    }

    public function test_distribucion_obligatoria_exige_la_suma_exacta(): void
    {
        $admin = $this->actingAsAdmin();
        $meta = $this->crearMeta('2026-10', TipoMeta::Colocacion, 1000000);
        $ana = $this->crearAsesor(Empresa::findOrFail($admin->empresa_id));
        $this->configurarMetas('distribucion_obligatoria_completa', true);

        $this->putJson("/api/v1/metas/{$meta->id}/asesores", ['asesores' => [
            ['usuario_id' => $ana->id, 'valor_objetivo' => 400000],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['asesores']);
    }

    public function test_distribuir_guarda_la_fila_por_asesor_y_registra_en_bitacora(): void
    {
        $admin = $this->actingAsAdmin();
        $meta = $this->crearMeta('2026-10', TipoMeta::Colocacion, 1000000);
        $ana = $this->crearAsesor(Empresa::findOrFail($admin->empresa_id));

        $this->putJson("/api/v1/metas/{$meta->id}/asesores", ['asesores' => [
            ['usuario_id' => $ana->id, 'valor_objetivo' => 600000],
        ]])->assertOk();

        $fila = MetaAsesor::query()->where('meta_id', $meta->id)->where('usuario_id', $ana->id)->sole();
        $this->assertSame('600000.00', $fila->valor_objetivo->aDecimalString());
        $this->assertEventoAuditoria('modificar', 'metas', 'meta', $meta->id);
    }

    public function test_distribuir_rechaza_un_asesor_de_otra_empresa(): void
    {
        $adminA = $this->actingAsAdmin();
        $meta = $this->crearMeta('2026-10', TipoMeta::Colocacion, 1000000);

        $adminB = $this->actingAsAdmin();
        $ajeno = $this->crearAsesor(Empresa::findOrFail($adminB->empresa_id));

        $this->actingAs($adminA, 'sanctum');

        $this->putJson("/api/v1/metas/{$meta->id}/asesores", ['asesores' => [
            ['usuario_id' => $ajeno->id, 'valor_objetivo' => 100000],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['asesores.0.usuario_id']);
    }

    public function test_configuracion_rechaza_valores_fuera_de_rango(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/v1/metas/configuracion', $this->payloadConfiguracion([
            'tolerancia_riesgo_puntos' => 101,
            'cumplimiento_parcial_minimo' => -5,
            'dia_de_cierre' => 'otro_dia',
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'tolerancia_riesgo_puntos',
            'cumplimiento_parcial_minimo',
            'dia_de_cierre',
        ]);
    }

    public function test_configuracion_registra_solo_lo_que_cambia_con_valor_anterior_y_nuevo(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/v1/metas/configuracion', $this->payloadConfiguracion(['tolerancia_riesgo_puntos' => 15]))->assertOk();

        $evento = $this->assertEventoAuditoria('modificar', 'metas', 'metas_configuracion');
        $this->assertSame(['tolerancia_riesgo_puntos' => '10'], $evento['valores_anteriores']);
        $this->assertSame(['tolerancia_riesgo_puntos' => '15'], $evento['valores_nuevos']);

        $this->putJson('/api/v1/metas/configuracion', $this->payloadConfiguracion(['tolerancia_riesgo_puntos' => 15]))->assertOk();

        $this->assertCount(1, $this->eventosDeAuditoria('modificar', 'metas'));
    }

    public function test_asesor_con_meta_consolidada_ve_la_empresa_y_solo_su_fila(): void
    {
        $admin = $this->actingAsAdmin();
        $empresa = Empresa::findOrFail($admin->empresa_id);
        $ana = $this->crearAsesor($empresa, 'Ana');
        $beto = $this->crearAsesor($empresa, 'Beto');
        $meta = $this->crearMeta('2026-10', TipoMeta::Colocacion, 1000000);
        $this->distribuirMeta($meta, $ana, 600000);
        $this->distribuirMeta($meta, $beto, 400000);
        $this->configurarMetas('meta_consolidada_visible_asesor', true);
        $this->simularHoy('2026-10-12 10:00:00');

        $this->actingAs($ana, 'sanctum');
        $respuesta = $this->getJson('/api/v1/metas?periodo=2026-10')->assertOk();

        $respuesta->assertJsonPath('ver_detalle', false);
        $this->assertSame(['Ana'], collect($respuesta->json('metas.0.asesores'))->pluck('nombre')->all());
        $this->assertSame('1000000.00', $respuesta->json('metas.0.valor_objetivo'));
    }

    public function test_la_configuracion_se_lee_con_los_valores_por_defecto(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/metas/configuracion')
            ->assertOk()
            ->assertJsonPath('parametros.tolerancia_riesgo_puntos', 10)
            ->assertJsonPath('parametros.modo_colocacion_refinanciaciones', 'solo_dinero_nuevo')
            ->assertJsonPath('parametros.dia_de_cierre', 'ultimo_dia');

        $this->assertSame(0, ConfiguracionMeta::query()->count());
    }

    public function test_eliminar_meta_sin_sesion_responde_401(): void
    {
        $this->actingAsAdmin();
        $meta = $this->crearMeta('2026-10', TipoMeta::Colocacion, 1000000);
        $this->salirDeSesion();

        $this->deleteJson("/api/v1/metas/{$meta->id}")->assertUnauthorized();
    }

    public function test_asesor_no_puede_eliminar_metas(): void
    {
        $admin = $this->actingAsAdmin();
        $meta = $this->crearMeta('2026-10', TipoMeta::Colocacion, 1000000);
        $this->actingAsAsesor(Empresa::findOrFail($admin->empresa_id));

        $this->deleteJson("/api/v1/metas/{$meta->id}")->assertForbidden();
        $this->assertNotSoftDeleted('metas', ['id' => $meta->id]);
    }

    public function test_eliminar_meta_inexistente_responde_404(): void
    {
        $this->actingAsAdmin();

        $this->deleteJson('/api/v1/metas/999999')->assertNotFound();
    }

    public function test_eliminar_meta_la_borra_con_su_distribucion_y_registra_en_bitacora(): void
    {
        $admin = $this->actingAsAdmin();
        $meta = $this->crearMeta('2026-10', TipoMeta::Colocacion, 1000000);
        $fila = $this->distribuirMeta($meta, $this->crearAsesor(Empresa::findOrFail($admin->empresa_id)), 600000);

        $this->deleteJson("/api/v1/metas/{$meta->id}")->assertNoContent();

        $this->assertSoftDeleted('metas', ['id' => $meta->id]);
        $this->assertSoftDeleted('metas_asesor', ['id' => $fila->id]);
        $this->assertEventoAuditoria('eliminar', 'metas', 'meta', $meta->id);
    }

    public function test_una_meta_eliminada_se_puede_volver_a_crear_en_el_mismo_periodo(): void
    {
        $this->actingAsAdmin();
        $meta = $this->crearMeta('2026-10', TipoMeta::Colocacion, 1000000);
        $this->deleteJson("/api/v1/metas/{$meta->id}")->assertNoContent();

        $this->postJson('/api/v1/metas', ['periodo' => '2026-10', 'tipo' => 'colocacion', 'valor_objetivo' => 2000000])->assertCreated();
    }

    public function test_un_admin_no_puede_eliminar_una_meta_de_otra_empresa(): void
    {
        $this->actingAsAdmin();
        $ajena = $this->crearMeta('2026-10', TipoMeta::Colocacion, 1000000);

        $this->actingAsAdmin();

        $this->deleteJson("/api/v1/metas/{$ajena->id}")->assertNotFound();
        $this->assertNotSoftDeleted('metas', ['id' => $ajena->id]);
    }
}
