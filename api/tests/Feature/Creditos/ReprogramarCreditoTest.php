<?php

namespace Tests\Feature\Creditos;

use App\Core\Dinero\Dinero;
use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Models\Credito;
use App\Empresas\Models\Empresa;
use App\Pagos\Enums\FormaPago;
use App\Pagos\Services\PagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

/** Reprogramar el mismo crédito: las cuotas sin pagos se rehacen desde el capital que representan. */
class ReprogramarCreditoTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesContabilidadFixtures;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    private const MOTIVO = 'El cliente cambió de empleador y pidió más plazo';

    /** 300 al 0 %, tres cuotas de 100 con vencimientos futuros. */
    private function credito(): Credito
    {
        $credito = $this->crearCredito($this->crearCliente(), ['valor_prestamo' => 300, 'valor_cuota' => 100, 'valor_total' => 300]);

        foreach ([1, 2, 3] as $numero) {
            $this->crearCuota($credito, ['numero_cuota' => $numero, 'fecha_vencimiento' => now()->addMonths($numero)->toDateString(), 'valor' => 100, 'valor_capital' => 100]);
        }

        return $credito->fresh();
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'cuotas_restantes' => 4,
            'fecha_primer_vencimiento' => now()->addMonths(2)->toDateString(),
            'motivo' => self::MOTIVO,
        ], $cambios);
    }

    private function url(Credito $credito): string
    {
        return "/api/v1/creditos/{$credito->id}/reprogramacion";
    }

    public function test_sin_autenticar_devuelve_401(): void
    {
        $this->actingAsAdmin();
        $credito = $this->credito();
        $this->salirDeSesion();

        $this->postJson($this->url($credito), $this->datos())->assertStatus(401);
    }

    public function test_asesor_sin_permiso_de_editar_creditos_recibe_403(): void
    {
        $admin = $this->actingAsAdmin();
        $asesor = $this->actingAsAsesor(Empresa::findOrFail($admin->empresa_id));
        $credito = $this->crearCredito($this->crearCliente(['asesor_id' => $asesor->id]));
        $this->crearCuota($credito, ['fecha_vencimiento' => now()->addMonth()->toDateString()]);

        $this->postJson($this->url($credito), $this->datos())->assertStatus(403);
        $this->assertDatabaseCount('reprogramaciones_credito', 0);
    }

    public function test_credito_inexistente_o_de_otra_empresa_responde_404(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/creditos/999999/reprogramacion', $this->datos())->assertStatus(404);

        $ajeno = $this->credito();
        $this->actingAsAdmin();

        $this->postJson($this->url($ajeno), $this->datos())->assertStatus(404);
        $this->assertDatabaseCount('reprogramaciones_credito', 0);
        $this->assertSame(3, $ajeno->cuotas()->count());
    }

    public function test_valida_los_datos(): void
    {
        $this->actingAsAdmin();
        $credito = $this->credito();

        $this->postJson($this->url($credito), [])->assertStatus(422)
            ->assertJsonValidationErrors(['cuotas_restantes', 'fecha_primer_vencimiento', 'motivo']);
        $this->postJson($this->url($credito), $this->datos(['cuotas_restantes' => 0, 'tasa_interes' => -1, 'fecha_primer_vencimiento' => '15/01/2026', 'motivo' => 'abc']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cuotas_restantes', 'tasa_interes', 'fecha_primer_vencimiento', 'motivo']);
    }

    public function test_acepta_un_primer_vencimiento_pasado_y_deja_vencidas_las_cuotas_ya_cumplidas(): void
    {
        $this->actingAsAdmin();
        $credito = $this->credito();
        $primerVencimiento = now()->subMonths(2)->startOfMonth()->addDays(9)->toDateString();

        $this->postJson($this->url($credito), $this->datos(['tasa_interes' => 0, 'fecha_primer_vencimiento' => $primerVencimiento]))->assertOk();

        $cuotas = $credito->cuotas()->orderBy('numero_cuota')->get();
        $this->assertSame($primerVencimiento, $cuotas[0]->fecha_vencimiento->toDateString());
        $this->assertSame(EstadoCuota::Vencida, $cuotas[0]->estado);
        $this->assertSame(EstadoCuota::Pendiente, $cuotas->last()->estado);
    }

    public function test_rechaza_un_primer_vencimiento_que_no_es_posterior_al_desembolso(): void
    {
        $this->actingAsAdmin();
        $credito = $this->credito();

        $this->postJson($this->url($credito), $this->datos(['fecha_primer_vencimiento' => $credito->fecha_desembolso->toDateString()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_primer_vencimiento' => __('validation.custom.fecha_primer_vencimiento.no_posterior_al_desembolso')]);
        $this->assertDatabaseCount('reprogramaciones_credito', 0);
    }

    public function test_rechaza_un_primer_vencimiento_anterior_al_ultimo_pago(): void
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $credito = $this->credito();
        app(PagoService::class)->registrarPago($credito, Dinero::desdePesos(100), FormaPago::Efectivo, null, null, now()->subDays(10)->toDateString());

        $this->postJson($this->url($credito), $this->datos(['fecha_primer_vencimiento' => now()->subDays(20)->toDateString()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_primer_vencimiento' => __('validation.custom.fecha_primer_vencimiento.anterior_al_ultimo_pago')]);
        $this->assertDatabaseCount('reprogramaciones_credito', 0);
    }

    public function test_solo_se_reprograma_un_credito_desembolsado(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCredito($this->crearCliente(), ['estado' => EstadoCredito::Aprobado]);
        $this->crearCuota($credito);

        $this->postJson($this->url($credito), $this->datos())->assertStatus(422)->assertJsonValidationErrors('estado');
    }

    public function test_reparte_el_capital_sin_pagos_en_el_nuevo_plazo_y_respeta_las_cuotas_pagadas(): void
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $credito = $this->credito();
        app(PagoService::class)->registrarPago($credito, Dinero::desdePesos(100), FormaPago::Efectivo, null, null);
        $primerVencimiento = now()->addMonths(2)->toDateString();

        $this->postJson($this->url($credito), $this->datos(['tasa_interes' => 0]))->assertOk();

        $cuotas = $credito->cuotas()->orderBy('numero_cuota')->get();
        $this->assertCount(5, $cuotas);
        $this->assertSame([100.0, EstadoCuota::Pagada], [$cuotas[0]->valor_pagado->aFloat(), $cuotas[0]->estado]);
        $this->assertSame([50.0, 50.0, 50.0, 50.0], $cuotas->slice(1)->map(fn ($cuota) => $cuota->valor_capital->aFloat())->values()->all());
        $this->assertSame($primerVencimiento, $cuotas[1]->fecha_vencimiento->toDateString());
        $this->assertSame([2, 3, 4, 5], $cuotas->slice(1)->pluck('numero_cuota')->values()->all());

        $credito->refresh();
        $this->assertSame(5, $credito->plazo);
        $this->assertSame('300.00', $credito->valor_total->aDecimalString());
        $this->assertDatabaseHas('reprogramaciones_credito', ['credito_id' => $credito->id, 'tipo' => 'reprogramacion', 'motivo' => self::MOTIVO]);
    }

    public function test_menos_cuotas_deja_las_sobrantes_eliminadas_y_cambia_la_tasa(): void
    {
        $this->actingAsAdmin();
        $credito = $this->credito();

        $this->postJson($this->url($credito), $this->datos(['cuotas_restantes' => 2, 'tasa_interes' => 10]))->assertOk();

        $this->assertSame(2, $credito->cuotas()->count());
        $this->assertSame(1, $credito->cuotas()->onlyTrashed()->count());
        $this->assertEquals(10, (float) $credito->fresh()->tasa_interes);
        $this->assertEquals(300, $credito->cuotas()->get()->sum(fn ($cuota) => $cuota->valor_capital->aFloat()));
    }

    /**
     * Otros gastos por cuota = valor prestado original × porcentaje — misma base que `conOtrosGastos()`,
     * `actualizarGastosAdministracion()` y el accessor `valorCuotaTotal` del crédito. Nunca el saldo pendiente:
     * si no, el total que muestra el crédito deja de coincidir con lo que quedó guardado en las cuotas nuevas.
     */
    public function test_otros_gastos_de_las_cuotas_nuevas_usan_el_valor_prestado_no_el_saldo_pendiente(): void
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $credito = $this->crearCredito($this->crearCliente(), ['valor_prestamo' => 300, 'valor_cuota' => 100, 'valor_total' => 300, 'gastos_administracion_pct' => 10]);
        foreach ([1, 2, 3] as $numero) {
            $this->crearCuota($credito, ['numero_cuota' => $numero, 'fecha_vencimiento' => now()->addMonths($numero)->toDateString(), 'valor' => 100, 'valor_capital' => 100]);
        }
        app(PagoService::class)->registrarPago($credito, Dinero::desdePesos(100), FormaPago::Efectivo, null, null);

        $this->postJson($this->url($credito), $this->datos(['tasa_interes' => 0]))->assertOk();

        $esperado = '30.00'; // 300 (valor_prestamo original) × 10 %, no 200 (saldo pendiente tras el abono) × 10 %.
        $nuevas = $credito->cuotas()->where('estado', '!=', EstadoCuota::Pagada)->get();
        $this->assertNotEmpty($nuevas);
        $this->assertTrue($nuevas->every(fn ($cuota) => $cuota->valor_otros_gastos->aDecimalString() === $esperado));
    }

    public function test_no_se_reversa_un_pago_anterior_a_la_reprogramacion(): void
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $credito = $this->credito();
        $pago = app(PagoService::class)->registrarPago($credito, Dinero::desdePesos(100), FormaPago::Efectivo, null, null)['pago'];

        $this->travel(1)->minutes();
        $this->postJson($this->url($credito), $this->datos())->assertOk();

        $this->postJson("/api/v1/pagos/{$pago->id}/reversar", ['motivo' => 'Cheque devuelto por el banco'])
            ->assertStatus(422)->assertJsonValidationErrors('pago');
    }
}
