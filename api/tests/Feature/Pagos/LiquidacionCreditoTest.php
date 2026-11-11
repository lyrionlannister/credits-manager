<?php

namespace Tests\Feature\Pagos;

use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Enums\TipoPago;
use App\Pagos\Enums\FormaPago;
use App\Pagos\Models\Pago;
use App\Pagos\Services\PagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

class LiquidacionCreditoTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesContabilidadFixtures;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
    }

    /**
     * Reproduce el ejemplo exacto que dio el cliente: capital pendiente 1.000.000,
     * tasa mensual 2%, 15 días transcurridos desde el último pago → interés 10.000.
     */
    public function test_calcula_liquidacion_con_el_ejemplo_del_cliente(): void
    {
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, [
            'valor_prestamo' => 1_000_000,
            'tasa_interes' => 2,
            'tipo_pago' => TipoPago::Mensual,
            'fecha_desembolso' => now()->subDays(45)->toDateString(),
        ]);
        $this->crearCuota($credito, ['numero_cuota' => 1, 'valor' => 20000, 'valor_interes' => 20000, 'valor_capital' => 0]);

        Pago::create([
            'credito_id' => $credito->id,
            'cliente_id' => $cliente->id,
            'valor_pago' => 20000,
            'fecha_pago' => now()->subDays(15),
            'forma_pago' => FormaPago::Efectivo,
        ]);

        $cotizacion = app(PagoService::class)->calcularLiquidacion($credito->fresh());

        $this->assertSame('1000000.00', $cotizacion['capital_pendiente']->aDecimalString());
        $this->assertEquals(15, $cotizacion['dias_transcurridos']);
        $this->assertSame('10000.00', $cotizacion['interes']->aDecimalString());
        $this->assertSame('1010000.00', $cotizacion['total']->aDecimalString());
    }

    public function test_sin_pagos_previos_cuenta_los_dias_desde_el_desembolso(): void
    {
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, [
            'valor_prestamo' => 900,
            'tasa_interes' => 3,
            'fecha_desembolso' => now()->subDays(10)->toDateString(),
        ]);
        $this->crearCuota($credito, ['numero_cuota' => 1]);

        $cotizacion = app(PagoService::class)->calcularLiquidacion($credito->fresh());

        $this->assertEquals(10, $cotizacion['dias_transcurridos']);
        $this->assertEquals(now()->subDays(10)->toDateString(), $cotizacion['fecha_referencia']);
    }

    public function test_con_fecha_de_pago_cuenta_los_dias_hasta_esa_fecha_y_no_hasta_hoy(): void
    {
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, [
            'valor_prestamo' => 1_000_000,
            'tasa_interes' => 2,
            'tipo_pago' => TipoPago::Mensual,
            'fecha_desembolso' => now('America/Bogota')->subDays(45)->toDateString(),
        ]);
        $this->crearCuota($credito, ['numero_cuota' => 1, 'valor' => 20000, 'valor_interes' => 20000, 'valor_capital' => 0]);
        $fechaPago = now('America/Bogota')->subDays(15)->toDateString();

        $cotizacion = app(PagoService::class)->calcularLiquidacion($credito->fresh(), $fechaPago);

        $this->assertEquals(30, $cotizacion['dias_transcurridos']);
        $this->assertSame('20000.00', $cotizacion['interes']->aDecimalString());
        $this->assertSame($fechaPago, $cotizacion['fecha_pago']);
    }

    public function test_liquidar_con_fecha_de_pago_guarda_el_pago_con_esa_fecha(): void
    {
        $this->crearReglasDeCreditoYPago();
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, ['valor_prestamo' => 900, 'tasa_interes' => 0, 'fecha_desembolso' => now()->subMonths(2)->toDateString()]);
        $this->crearCuota($credito, ['numero_cuota' => 1, 'fecha_vencimiento' => now()->addMonth()->toDateString()]);
        $fechaPago = now('America/Bogota')->subDays(10)->toDateString();

        $resultado = app(PagoService::class)->liquidarCredito($credito->fresh(), FormaPago::Transferencia, null, null, $fechaPago);

        $this->assertEquals($fechaPago, $resultado['pago']->fresh()->fecha_pago->toDateString());
        $this->assertSame(EstadoCredito::Pagado, $credito->fresh()->estado);
    }

    public function test_rechaza_una_fecha_de_pago_anterior_al_ultimo_pago_vigente(): void
    {
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, ['fecha_desembolso' => now()->subDays(60)->toDateString()]);
        $this->crearCuota($credito, ['numero_cuota' => 1]);
        Pago::create([
            'credito_id' => $credito->id,
            'cliente_id' => $cliente->id,
            'valor_pago' => 100,
            'fecha_pago' => now()->subDays(10),
            'forma_pago' => FormaPago::Efectivo,
        ]);

        try {
            app(PagoService::class)->calcularLiquidacion($credito->fresh(), now()->subDays(20)->toDateString());
            $this->fail('Debió rechazar la fecha anterior al último pago.');
        } catch (ValidationException $excepcion) {
            $this->assertArrayHasKey('fecha_pago', $excepcion->errors());
        }
    }

    public function test_liquidar_credito_cierra_todas_las_cuotas_y_marca_el_credito_como_pagado(): void
    {
        $this->crearReglasDeCreditoYPago();
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, ['valor_prestamo' => 900, 'tasa_interes' => 0]);
        $this->crearCuota($credito, ['numero_cuota' => 1, 'fecha_vencimiento' => '2026-01-01']);
        $this->crearCuota($credito, ['numero_cuota' => 2, 'fecha_vencimiento' => '2026-02-01']);
        $this->crearCuota($credito, ['numero_cuota' => 3, 'fecha_vencimiento' => '2026-03-01']);

        $resultado = app(PagoService::class)->liquidarCredito($credito->fresh(), FormaPago::Transferencia, null, null);

        $cuotas = $credito->cuotas()->orderBy('numero_cuota')->get();
        foreach ($cuotas as $cuota) {
            $this->assertSame(EstadoCuota::Pagada, $cuota->estado);
        }

        // `saldo_capital` es el saldo corrido tras cada cuota: la última queda en cero.
        $this->assertSame(['600.00', '300.00', '0.00'], $cuotas->map(fn ($cuota) => $cuota->saldo_capital->aDecimalString())->all());

        $this->assertSame(EstadoCredito::Pagado, $credito->fresh()->estado);
        $this->assertDatabaseCount('pagos', 1);
        $this->assertSame($resultado['total']->aDecimalString(), $resultado['pago']->valor_pago->aDecimalString());
        $this->assertEquals('Liquidación anticipada del crédito', $resultado['pago']->observacion);
    }

    public function test_rechaza_liquidar_un_credito_que_no_esta_desembolsado(): void
    {
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, ['estado' => EstadoCredito::Aprobado]);
        $this->crearCuota($credito, ['numero_cuota' => 1]);

        $this->expectException(ValidationException::class);

        app(PagoService::class)->calcularLiquidacion($credito->fresh());
    }
}
