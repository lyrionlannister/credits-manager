<?php

namespace Tests\Feature\Pagos;

use App\Contabilidad\Enums\EventoContable;
use App\Core\Dinero\Dinero;
use App\Pagos\Enums\FormaPago;
use App\Pagos\Services\PagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

class PagoOtrosGastosTest extends TestCase
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

    private function configurarReglas(bool $conOtros = true): void
    {
        $bancos = $this->crearCuenta();
        $cartera = $this->crearCuenta();
        $ingresosIntereses = $this->crearCuenta();
        $tipo = $this->crearTipoComprobante();

        $this->crearRegla(EventoContable::RecaudoCapital, $bancos, $cartera, $tipo);
        $this->crearRegla(EventoContable::RecaudoInteres, $bancos, $ingresosIntereses, $tipo);

        if ($conOtros) {
            $this->crearRegla(EventoContable::RecaudoOtrosGastos, $bancos, $this->crearCuenta(), $tipo);
        }
    }

    public function test_el_pago_cubre_primero_interes_luego_otros_gastos_y_al_final_capital(): void
    {
        $this->configurarReglas();
        $credito = $this->crearCredito($this->crearCliente(), ['gastos_administracion_pct' => 1]);
        $cuota = $this->crearCuota($credito, [
            'numero_cuota' => 1,
            'valor' => 300,
            'valor_capital' => 250,
            'valor_interes' => 50,
            'valor_otros_gastos' => 30,
            'valor_pagado' => 0,
        ]);

        app(PagoService::class)->registrarPago($credito->fresh(), Dinero::desdePesos(80), FormaPago::Efectivo, null, null);

        $pago = $credito->pagos()->sole();
        $this->assertSame(5000, $pago->valor_interes->centavos());
        $this->assertSame(3000, $pago->valor_otros_gastos->centavos());
        $this->assertSame(0, $pago->valor_capital->centavos());
        $this->assertSame(8000, $cuota->fresh()->valor_pagado->centavos());
    }

    public function test_la_cuota_se_marca_pagada_solo_al_cubrir_capital_interes_y_otros_gastos(): void
    {
        $this->configurarReglas();
        $credito = $this->crearCredito($this->crearCliente(), ['gastos_administracion_pct' => 1]);
        $cuota = $this->crearCuota($credito, [
            'numero_cuota' => 1,
            'valor' => 300,
            'valor_capital' => 250,
            'valor_interes' => 50,
            'valor_otros_gastos' => 30,
            'valor_pagado' => 0,
        ]);

        app(PagoService::class)->registrarPago($credito->fresh(), Dinero::desdePesos(300), FormaPago::Efectivo, null, null);

        $this->assertSame('pendiente', $cuota->fresh()->estado->value, 'Faltan 30 de otros gastos.');

        app(PagoService::class)->registrarPago($credito->fresh(), Dinero::desdePesos(30), FormaPago::Efectivo, null, null);

        $this->assertSame('pagada', $cuota->fresh()->estado->value);
    }

    public function test_abono_parcial_ajusta_los_otros_gastos_de_las_cuotas_restantes_al_saldo(): void
    {
        $this->configurarReglas();
        $credito = $this->crearCredito($this->crearCliente(), ['valor_prestamo' => 900, 'gastos_administracion_pct' => 1]);
        foreach ([1, 2, 3] as $numero) {
            $this->crearCuota($credito, [
                'numero_cuota' => $numero,
                'valor' => 300,
                'valor_capital' => 300,
                'valor_interes' => 0,
                'valor_otros_gastos' => 9,
                'valor_pagado' => 0,
            ]);
        }

        // Paga la primera cuota completa (300 de capital + 9 de otros): el saldo de capital queda en 600.
        app(PagoService::class)->registrarPago($credito->fresh(), Dinero::desdePesos(309), FormaPago::Efectivo, null, null);

        // 600 / 900 del 1 % del capital inicial = 6 por cuota restante.
        $restantes = $credito->fresh()->cuotas()->where('numero_cuota', '>', 1)->get();
        $this->assertSame([600, 600], $restantes->map(fn ($c) => $c->valor_otros_gastos->centavos())->all());
        $this->assertSame([30000, 30000], $restantes->map(fn ($c) => $c->valor_capital->centavos())->all(), 'El capital de las cuotas restantes no cambia.');
    }

    public function test_la_liquidacion_cobra_otros_gastos_vencidos_completos_y_el_periodo_en_curso_por_dias(): void
    {
        $credito = $this->crearCredito($this->crearCliente(), ['gastos_administracion_pct' => 1, 'valor_prestamo' => 900]);
        $this->crearCuota($credito, [
            'numero_cuota' => 1,
            'fecha_vencimiento' => now()->subDays(20)->toDateString(),
            'valor' => 300, 'valor_capital' => 300, 'valor_interes' => 0, 'valor_otros_gastos' => 10, 'valor_pagado' => 0,
        ]);
        $this->crearCuota($credito, [
            'numero_cuota' => 2,
            'fecha_vencimiento' => now()->addDays(10)->toDateString(),
            'valor' => 300, 'valor_capital' => 300, 'valor_interes' => 0, 'valor_otros_gastos' => 10, 'valor_pagado' => 0,
        ]);
        $this->crearCuota($credito, [
            'numero_cuota' => 3,
            'fecha_vencimiento' => now()->addDays(40)->toDateString(),
            'valor' => 300, 'valor_capital' => 300, 'valor_interes' => 0, 'valor_otros_gastos' => 10, 'valor_pagado' => 0,
        ]);

        $liquidacion = app(PagoService::class)->calcularLiquidacion($credito->fresh());

        // Cuota 1 vencida: 10 completos. Cuota 2 en curso: 20 de 30 días sobre 10 = 6,67. Cuota 3 aún no inicia: 0.
        $this->assertSame(1000 + 667, $liquidacion['otros_gastos']->centavos());
        $this->assertSame(
            $liquidacion['capital_pendiente']->sumar($liquidacion['interes'])->sumar($liquidacion['otros_gastos'])->centavos(),
            $liquidacion['total']->centavos(),
        );
    }

    public function test_la_liquidacion_con_fecha_pasada_cuenta_los_otros_gastos_hasta_esa_fecha_y_no_hasta_hoy(): void
    {
        $credito = $this->crearCredito($this->crearCliente(), [
            'gastos_administracion_pct' => 1,
            'valor_prestamo' => 900,
            'fecha_desembolso' => now('America/Bogota')->subDays(50)->toDateString(),
        ]);
        foreach ([-20, 10, 40] as $posicion => $dias) {
            $this->crearCuota($credito, [
                'numero_cuota' => $posicion + 1,
                'fecha_vencimiento' => now('America/Bogota')->addDays($dias)->toDateString(),
                'valor' => 300, 'valor_capital' => 300, 'valor_interes' => 0, 'valor_otros_gastos' => 10, 'valor_pagado' => 0,
            ]);
        }

        $liquidacion = app(PagoService::class)->calcularLiquidacion($credito->fresh(), now('America/Bogota')->subDays(10)->toDateString());

        // A la fecha de pago: cuota 1 vencida, 10 completos; cuota 2 lleva 10 de 30 días, 3,33; cuota 3 no inicia. Hoy darían 16,67.
        $this->assertSame(1000 + 333, $liquidacion['otros_gastos']->centavos());
    }

    public function test_el_recaudo_con_otros_gastos_genera_un_comprobante_balanceado(): void
    {
        $this->configurarReglas();
        $credito = $this->crearCredito($this->crearCliente(), ['gastos_administracion_pct' => 1]);
        $this->crearCuota($credito, [
            'numero_cuota' => 1,
            'valor' => 300,
            'valor_capital' => 250,
            'valor_interes' => 50,
            'valor_otros_gastos' => 30,
            'valor_pagado' => 0,
        ]);

        app(PagoService::class)->registrarPago($credito->fresh(), Dinero::desdePesos(330), FormaPago::Efectivo, null, null);

        $this->assertDatabaseHas('comprobantes', [
            'modulo_origen' => 'pagos',
            'estado' => 'contabilizado',
            'total_debito' => 33000,
            'total_credito' => 33000,
        ]);
        $this->assertDatabaseHas('asientos_contables', ['credito' => 3000]); // otros gastos
    }

    public function test_un_pago_con_otros_gastos_sin_regla_de_otros_se_rechaza(): void
    {
        $this->configurarReglas(conOtros: false);
        $credito = $this->crearCredito($this->crearCliente(), ['gastos_administracion_pct' => 1]);
        $this->crearCuota($credito, [
            'numero_cuota' => 1,
            'valor' => 300,
            'valor_capital' => 250,
            'valor_interes' => 50,
            'valor_otros_gastos' => 30,
            'valor_pagado' => 0,
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(PagoService::class)->registrarPago($credito->fresh(), Dinero::desdePesos(330), FormaPago::Efectivo, null, null);
        } finally {
            $this->assertDatabaseCount('pagos', 0);
            $this->assertDatabaseCount('comprobantes', 0);
        }
    }
}
