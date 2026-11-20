<?php

namespace Tests\Feature\Creditos;

use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Models\Credito;
use App\Creditos\Models\Cuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesAuditoriaFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

class PrevisualizarEdicionCreditoTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesAuditoriaFixtures;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    private function creditoSolicitado(): Credito
    {
        $respuesta = $this->postJson('/api/v1/creditos', [
            'cliente_id' => $this->crearCliente()->id,
            'tipo_credito' => 'nuevo',
            'valor_prestamo' => 1000,
            'tasa_interes' => 2,
            'plazo' => 3,
            'tipo_pago' => 'mensual',
            'fecha_desembolso' => '2026-01-01',
        ])->assertCreated();

        return Credito::findOrFail($respuesta->json('credito.id'));
    }

    private function nuevasCondiciones(array $cambios = []): array
    {
        return array_merge([
            'valor_prestamo' => 1200,
            'tasa_efectiva_anual' => 28.59,
            'plazo' => 6,
            'tipo_pago' => 'quincenal',
            'fecha_desembolso' => '2026-02-01',
        ], $cambios);
    }

    private function url(int $creditoId): string
    {
        return "/api/v1/creditos/{$creditoId}/previsualizar";
    }

    public function test_rechaza_una_previsualizacion_sin_los_campos_obligatorios(): void
    {
        $this->actingAsAdmin();
        $credito = $this->creditoSolicitado();

        $this->postJson($this->url($credito->id), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valor_prestamo', 'tasa_efectiva_anual', 'plazo', 'tipo_pago', 'fecha_desembolso']);
    }

    public function test_rechaza_previsualizar_sin_autenticar(): void
    {
        $this->actingAsAdmin();
        $credito = $this->creditoSolicitado();
        $this->salirDeSesion();

        $this->postJson($this->url($credito->id), $this->nuevasCondiciones())->assertStatus(401);
    }

    public function test_un_asesor_sin_el_permiso_de_editar_no_puede_previsualizar(): void
    {
        $empresa = $this->crearEmpresaDePrueba();
        $this->actingAsAdmin($empresa);
        $credito = $this->creditoSolicitado();
        $this->actingAsAsesor($empresa)->update(['es_asesor_global' => true]);

        $this->postJson($this->url($credito->id), $this->nuevasCondiciones())->assertStatus(403);
    }

    public function test_un_credito_inexistente_responde_404(): void
    {
        $this->actingAsAdmin();

        $this->postJson($this->url(999999), $this->nuevasCondiciones())->assertStatus(404);
    }

    public function test_no_previsualiza_un_credito_de_otra_empresa(): void
    {
        $this->actingAsAdmin();
        $ajeno = $this->creditoSolicitado();

        $this->actingAsAdmin();

        $this->postJson($this->url($ajeno->id), $this->nuevasCondiciones())->assertStatus(404);
    }

    public function test_rechaza_previsualizar_un_credito_ya_desembolsado(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCredito($this->crearCliente(), ['estado' => EstadoCredito::Desembolsado]);

        $this->postJson($this->url($credito->id), $this->nuevasCondiciones())
            ->assertStatus(422)
            ->assertJsonValidationErrors('credito');
    }

    public function test_devuelve_el_plan_nuevo_sin_cambiar_el_credito_ni_sus_cuotas(): void
    {
        $this->actingAsAdmin();
        $credito = $this->creditoSolicitado();
        $cuotasAntes = Cuota::where('credito_id', $credito->id)->pluck('valor')->map->centavos()->all();

        $this->postJson($this->url($credito->id), $this->nuevasCondiciones())
            ->assertOk()
            ->assertJsonPath('valor_prestamo', '1200.00')
            ->assertJsonPath('tasa_interes', '2.117600')
            ->assertJsonCount(6, 'cuotas');

        $this->assertDatabaseHas('creditos', ['id' => $credito->id, 'plazo' => 3, 'tipo_pago' => 'mensual', 'tasa_efectiva_anual' => null]);
        $this->assertSame($cuotasAntes, Cuota::where('credito_id', $credito->id)->pluck('valor')->map->centavos()->all());
    }
}
