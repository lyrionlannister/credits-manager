<?php

namespace Tests\Feature\Creditos;

use App\Core\Dinero\Dinero;
use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Enums\TipoCredito;
use App\Creditos\Enums\TipoPago;
use App\Creditos\Services\CreditoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

class CreditoServiceTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesContabilidadFixtures;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
    }

    public function test_crear_credito_nuevo_genera_las_cuotas_de_la_amortizacion(): void
    {
        $cliente = $this->crearCliente();

        $credito = app(CreditoService::class)->crearCredito([
            'cliente_id' => $cliente->id,
            'tipo_credito' => TipoCredito::Nuevo->value,
            'valor_prestamo' => 1500000,
            'tasa_interes' => 7,
            'plazo' => 6,
            'tipo_pago' => TipoPago::Mensual->value,
            'fecha_desembolso' => '2026-01-01',
        ]);

        $this->assertSame(EstadoCredito::Solicitado, $credito->estado);
        $this->assertCount(6, $credito->cuotas);
        $this->assertTrue($credito->valor_cuota->esPositivo());
        $this->assertEqualsWithDelta(
            $credito->valor_total->aFloat(),
            Dinero::sumarVarios(...$credito->cuotas->pluck('valor')->all())->aFloat(),
            0.01,
        );
    }

    public function test_refinanciacion_congela_el_credito_origen_al_desembolsar_sin_tocar_sus_cuotas(): void
    {
        $cliente = $this->crearCliente();
        $origen = $this->crearCredito($cliente);
        $this->crearCuota($origen, ['numero_cuota' => 1, 'estado' => EstadoCuota::Pagada, 'valor' => 300, 'valor_pagado' => 300]);
        $this->crearCuota($origen, ['numero_cuota' => 2, 'estado' => EstadoCuota::Pendiente, 'valor' => 300, 'valor_pagado' => 0]);
        $this->crearCuota($origen, ['numero_cuota' => 3, 'estado' => EstadoCuota::Vencida, 'valor' => 300, 'valor_pagado' => 100]);

        $nuevo = app(CreditoService::class)->crearCredito([
            'cliente_id' => $cliente->id,
            'tipo_credito' => TipoCredito::Refinanciacion->value,
            'credito_origen_id' => $origen->id,
            'valor_adicional' => 500,
            'saldo_refinanciado_previsualizado' => 500, // capital pendiente del origen: 900 - 300 (cuota 1) - 100 (abono de la cuota 3).
            'tasa_interes' => 5,
            'plazo' => 3,
            'tipo_pago' => TipoPago::Mensual->value,
            'fecha_desembolso' => '2026-01-01',
        ]);

        $this->assertSame(EstadoCredito::Desembolsado, $origen->fresh()->estado, 'Al solicitar, el original no se congela.');
        $this->assertSame(EstadoCredito::Solicitado, $nuevo->estado);
        $this->assertCount(3, $nuevo->cuotas);

        $servicio = app(CreditoService::class);
        $servicio->desembolsar($servicio->aprobar($nuevo));

        $this->assertSame(EstadoCredito::Refinanciado, $origen->fresh()->estado);

        // El original es solo historial: ninguna cuota cambia de estado ni se le inventa un pago.
        $cuotasOrigen = $origen->cuotas()->orderBy('numero_cuota')->get();

        $this->assertSame(EstadoCuota::Pagada, $cuotasOrigen[0]->estado);
        $this->assertSame('300.00', $cuotasOrigen[0]->valor_pagado->aDecimalString());
        $this->assertSame(EstadoCuota::Pendiente, $cuotasOrigen[1]->estado);
        $this->assertSame('0.00', $cuotasOrigen[1]->valor_pagado->aDecimalString());
        $this->assertSame(EstadoCuota::Vencida, $cuotasOrigen[2]->estado);
        $this->assertSame('100.00', $cuotasOrigen[2]->valor_pagado->aDecimalString());
    }

    public function test_aprueba_desembolsa_y_cancela_siguiendo_las_transiciones_validas(): void
    {
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, ['estado' => EstadoCredito::Solicitado]);

        $aprobado = app(CreditoService::class)->aprobar($credito);
        $this->assertSame(EstadoCredito::Aprobado, $aprobado->estado);

        $desembolsado = app(CreditoService::class)->desembolsar($aprobado);
        $this->assertSame(EstadoCredito::Desembolsado, $desembolsado->estado);
    }

    public function test_cancela_un_credito_solicitado_o_aprobado(): void
    {
        $cliente = $this->crearCliente();

        $solicitado = $this->crearCredito($cliente, ['estado' => EstadoCredito::Solicitado]);
        $this->assertSame(EstadoCredito::Cancelado, app(CreditoService::class)->cancelar($solicitado)->estado);

        $aprobado = $this->crearCredito($cliente, ['estado' => EstadoCredito::Aprobado]);
        $this->assertSame(EstadoCredito::Cancelado, app(CreditoService::class)->cancelar($aprobado)->estado);
    }

    public function test_rechaza_aprobar_un_credito_que_no_esta_solicitado(): void
    {
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, ['estado' => EstadoCredito::Desembolsado]);

        $this->expectException(ValidationException::class);

        app(CreditoService::class)->aprobar($credito);
    }

    public function test_rechaza_desembolsar_un_credito_que_no_esta_aprobado(): void
    {
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, ['estado' => EstadoCredito::Solicitado]);

        $this->expectException(ValidationException::class);

        app(CreditoService::class)->desembolsar($credito);
    }

    public function test_rechaza_cancelar_un_credito_desembolsado(): void
    {
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, ['estado' => EstadoCredito::Desembolsado]);

        $this->expectException(ValidationException::class);

        app(CreditoService::class)->cancelar($credito);
    }

    public function test_listado_de_creditos_incluye_el_saldo_pendiente_de_sus_cuotas(): void
    {
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente);
        $this->crearCuota($credito, ['numero_cuota' => 1, 'valor' => 300, 'valor_pagado' => 300, 'estado' => EstadoCuota::Pagada]);
        $this->crearCuota($credito, ['numero_cuota' => 2, 'valor' => 300, 'valor_pagado' => 100, 'estado' => EstadoCuota::Pendiente]);
        $this->crearCuota($credito, ['numero_cuota' => 3, 'valor' => 300, 'valor_otros_gastos' => 50, 'valor_pagado' => 0, 'estado' => EstadoCuota::Pendiente]);

        $listado = app(CreditoService::class)->listarCreditos(null, 10, 1);
        $fila = $listado->getCollection()->firstWhere('id', $credito->id);

        $this->assertSame('550.00', $fila->saldo);
    }
}
