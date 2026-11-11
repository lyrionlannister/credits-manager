<?php

namespace Tests\Feature\Pagos;

use App\Core\Dinero\Dinero;
use App\Creditos\Models\Credito;
use App\Pagos\Enums\FormaPago;
use App\Pagos\Models\Pago;
use App\Pagos\Services\PagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

/** Una empresa no ve, no reversa ni descarga pagos, recibos ni estados de cuenta de otra. */
class AislamientoEntreEmpresasPagosTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesContabilidadFixtures;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    /** @return array{0: Credito, 1: Pago} */
    private function pagoDeEmpresaA(): array
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $credito = $this->crearCredito($this->crearCliente(), ['valor_prestamo' => 300, 'valor_cuota' => 100, 'valor_total' => 300, 'plazo' => 3]);
        $this->crearCuota($credito, ['numero_cuota' => 1, 'fecha_vencimiento' => now()->addMonth()->toDateString(), 'valor' => 300, 'valor_capital' => 300]);
        $pago = app(PagoService::class)->registrarPago($credito->fresh(), Dinero::desdePesos(100), FormaPago::Efectivo, null, null)['pago'];

        return [$credito, $pago];
    }

    public function test_otra_empresa_no_ve_pagos_ni_recibos_ajenos(): void
    {
        [, $pago] = $this->pagoDeEmpresaA();

        $this->actingAsAdmin();

        $this->getJson('/api/v1/pagos')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/recibos')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/pagos/{$pago->id}/recibo/pdf")->assertStatus(404);
    }

    public function test_otra_empresa_no_reversa_un_pago_ajeno_ni_toca_sus_cuotas(): void
    {
        [$credito, $pago] = $this->pagoDeEmpresaA();
        $pagadoAntes = $credito->cuotas()->firstOrFail()->valor_pagado->aFloat();

        $this->actingAsAdmin();

        $this->postJson("/api/v1/pagos/{$pago->id}/reversar", ['motivo' => 'Intento de reversa ajena'])->assertStatus(404);

        $this->assertDatabaseMissing('reversas_pago', ['pago_id' => $pago->id]);
        $this->assertEquals($pagadoAntes, $credito->cuotas()->firstOrFail()->valor_pagado->aFloat());
    }

    public function test_otra_empresa_no_cotiza_ni_liquida_un_credito_ajeno_con_fecha_de_pago(): void
    {
        [$credito] = $this->pagoDeEmpresaA();
        $fechaPago = now('America/Bogota')->toDateString();

        $this->actingAsAdmin();

        $this->getJson("/api/v1/creditos/{$credito->id}/liquidacion?fecha_pago={$fechaPago}")->assertStatus(404);
        $this->postJson("/api/v1/creditos/{$credito->id}/liquidacion", ['forma_pago' => 'efectivo', 'fecha_pago' => $fechaPago])->assertStatus(404);
        $this->assertDatabaseCount('pagos', 1);
    }

    public function test_otra_empresa_no_reversa_una_liquidacion_ajena(): void
    {
        [$credito] = $this->pagoDeEmpresaA();
        $liquidacion = app(PagoService::class)->liquidarCredito($credito->fresh(), FormaPago::Efectivo, null, null)['pago'];

        $this->actingAsAdmin();

        $this->postJson("/api/v1/pagos/{$liquidacion->id}/reversar", ['motivo' => 'Intento de reversa ajena'])->assertStatus(404);
        $this->assertDatabaseMissing('reversas_pago', ['pago_id' => $liquidacion->id]);
    }

    public function test_otra_empresa_no_ve_el_estado_de_cuenta_ajeno(): void
    {
        [$credito] = $this->pagoDeEmpresaA();

        $this->actingAsAdmin();

        $this->getJson("/api/v1/creditos/{$credito->id}/estado-cuenta")->assertStatus(404);
    }
}
