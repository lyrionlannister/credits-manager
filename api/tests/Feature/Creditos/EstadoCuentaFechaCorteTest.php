<?php

namespace Tests\Feature\Creditos;

use App\Core\Dinero\Dinero;
use App\Pagos\Enums\FormaPago;
use App\Pagos\Services\PagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

class EstadoCuentaFechaCorteTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesContabilidadFixtures;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    public function test_el_estado_de_cuenta_trae_la_fecha_de_corte_del_servidor_y_muestra_los_pagos_reversados(): void
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $credito = $this->crearCredito($this->crearCliente(), ['valor_prestamo' => 300, 'valor_cuota' => 100, 'valor_total' => 300, 'plazo' => 3]);
        $this->crearCuota($credito, ['numero_cuota' => 1, 'fecha_vencimiento' => now()->addMonth()->toDateString(), 'valor' => 300, 'valor_capital' => 300]);
        $pago = app(PagoService::class)->registrarPago($credito->fresh(), Dinero::desdePesos(100), FormaPago::Efectivo, null, null)['pago'];
        $this->postJson("/api/v1/pagos/{$pago->id}/reversar", ['motivo' => 'Pago registrado por error'])->assertOk();

        $respuesta = $this->getJson("/api/v1/creditos/{$credito->id}/estado-cuenta");

        $respuesta->assertOk();
        $respuesta->assertJsonPath('fecha_corte', now('America/Bogota')->toDateString());
        $respuesta->assertJsonPath('pagos.0.reversa.motivo', 'Pago registrado por error');
        $respuesta->assertJsonPath('credito.cuotas.0.fecha_pago', null);
    }

    public function test_cada_cuota_muestra_la_fecha_real_en_que_se_pago_y_no_la_del_plan(): void
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $credito = $this->crearCredito($this->crearCliente(), ['valor_prestamo' => 300, 'fecha_desembolso' => now()->subMonths(4)->toDateString()]);
        foreach ([1, 2, 3] as $numero) {
            $this->crearCuota($credito, ['numero_cuota' => $numero, 'fecha_vencimiento' => now()->subMonths(4 - $numero)->toDateString(), 'valor' => 100, 'valor_capital' => 100]);
        }
        $fechaReal = now('America/Bogota')->subDays(3)->toDateString();
        app(PagoService::class)->registrarPago($credito->fresh(), Dinero::desdePesos(100), FormaPago::Efectivo, null, null, $fechaReal);
        app(PagoService::class)->liquidarCredito($credito->fresh(), FormaPago::Efectivo, null, null);

        $respuesta = $this->getJson("/api/v1/creditos/{$credito->id}/estado-cuenta")->assertOk();

        $respuesta->assertJsonPath('credito.cuotas.0.fecha_pago', $fechaReal);
        $respuesta->assertJsonPath('credito.cuotas.1.fecha_pago', now('America/Bogota')->toDateString());
        $respuesta->assertJsonPath('credito.cuotas.2.fecha_pago', now('America/Bogota')->toDateString());

        $this->get("/api/v1/creditos/{$credito->id}/estado-cuenta/pdf")->assertOk();
    }
}
