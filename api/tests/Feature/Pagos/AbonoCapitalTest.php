<?php

namespace Tests\Feature\Pagos;

use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Models\Credito;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

/** Lo que excede lo vencido y la cuota en curso va a capital: mismo plazo, cuotas siguientes más pequeñas. */
class AbonoCapitalTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesContabilidadFixtures;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    private const MOTIVO = 'Pago aplicado al crédito equivocado';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
    }

    /** 240 al 0 %, tres cuotas de 80 de capital; `$vencidas` de ellas ya vencieron. */
    private function credito(int $vencidas = 0, int $cuotas = 3): Credito
    {
        $credito = $this->crearCredito($this->crearCliente(), ['valor_prestamo' => 80 * $cuotas, 'valor_cuota' => 80, 'valor_total' => 80 * $cuotas, 'plazo' => $cuotas]);

        for ($numero = 1; $numero <= $cuotas; $numero++) {
            $this->crearCuota($credito, [
                'numero_cuota' => $numero,
                'fecha_vencimiento' => ($numero <= $vencidas ? now()->subMonths($vencidas - $numero + 1) : now()->addMonths($numero - $vencidas))->toDateString(),
                'valor' => 80,
                'valor_capital' => 80,
                'estado' => $numero <= $vencidas ? EstadoCuota::Vencida : EstadoCuota::Pendiente,
            ]);
        }

        return $credito->fresh();
    }

    private function pagar(Credito $credito, int $valor)
    {
        return $this->postJson("/api/v1/creditos/{$credito->id}/pagos", ['valor_pago' => $valor, 'forma_pago' => 'efectivo']);
    }

    private function cuotas(Credito $credito): array
    {
        return $credito->cuotas()->orderBy('numero_cuota')->get()
            ->map(fn ($cuota) => [$cuota->valor->aFloat(), $cuota->valor_capital->aFloat(), $cuota->valor_pagado->aFloat(), $cuota->estado])
            ->all();
    }

    public function test_el_excedente_sobre_la_cuota_en_curso_se_abona_a_capital_con_el_mismo_plazo(): void
    {
        $credito = $this->credito();
        $fechas = $credito->cuotas()->orderBy('numero_cuota')->pluck('fecha_vencimiento')->map->toDateString()->all();

        $respuesta = $this->pagar($credito, 120)->assertStatus(201);

        $respuesta->assertJsonPath('abono_capital', '40.00');
        $this->assertSame([
            [120.0, 120.0, 120.0, EstadoCuota::Pagada],
            [60.0, 60.0, 0.0, EstadoCuota::Pendiente],
            [60.0, 60.0, 0.0, EstadoCuota::Pendiente],
        ], $this->cuotas($credito));
        $this->assertSame($fechas, $credito->cuotas()->orderBy('numero_cuota')->pluck('fecha_vencimiento')->map->toDateString()->all());

        $credito->refresh();
        $this->assertSame(3, $credito->plazo);
        $this->assertSame('240.00', $credito->valor_total->aDecimalString());
        $this->assertDatabaseHas('reprogramaciones_credito', ['credito_id' => $credito->id, 'tipo' => 'abono_capital', 'valor_abono' => 4000]);
        $this->assertDatabaseHas('pagos', ['id' => $respuesta->json('pago.id'), 'valor_capital' => 12000]);
    }

    public function test_un_solo_pago_cubre_las_cuotas_atrasadas_y_el_resto_va_a_capital(): void
    {
        $credito = $this->credito(vencidas: 2, cuotas: 4);

        // Dos vencidas (160) + la cuota en curso (80) + 40 a capital.
        $this->pagar($credito, 280)->assertStatus(201)->assertJsonPath('abono_capital', '40.00');

        $this->assertSame([
            [80.0, 80.0, 80.0, EstadoCuota::Pagada],
            [80.0, 80.0, 80.0, EstadoCuota::Pagada],
            [120.0, 120.0, 120.0, EstadoCuota::Pagada],
            [40.0, 40.0, 0.0, EstadoCuota::Pendiente],
        ], $this->cuotas($credito));
        $this->assertSame(EstadoCredito::Desembolsado, $credito->fresh()->estado);
    }

    public function test_abonar_todo_el_capital_salda_el_credito(): void
    {
        $credito = $this->credito();

        $this->pagar($credito, 240)->assertStatus(201)->assertJsonPath('abono_capital', '160.00');

        $this->assertSame(EstadoCredito::Pagado, $credito->fresh()->estado);
        $this->assertEquals(0, $credito->cuotas()->where('estado', '!=', 'pagada')->count());
    }

    public function test_un_pago_mayor_que_todo_lo_que_se_debe_se_rechaza(): void
    {
        $credito = $this->credito();

        $this->pagar($credito, 241)->assertStatus(422)->assertJsonValidationErrors('valor_pago');

        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseCount('reprogramaciones_credito', 0);
    }

    public function test_reversar_el_pago_devuelve_el_plan_anterior_al_abono(): void
    {
        $credito = $this->credito();
        $antes = $this->cuotas($credito);
        $pago = $this->pagar($credito, 120)->json('pago.id');

        $this->postJson("/api/v1/pagos/{$pago}/reversar", ['motivo' => self::MOTIVO])->assertOk();

        $this->assertSame($antes, $this->cuotas($credito));
        $this->assertSame('240.00', $credito->fresh()->valor_total->aDecimalString());
        $this->assertSoftDeleted('reprogramaciones_credito', ['pago_id' => $pago]);
    }

    public function test_pagar_solo_la_cuota_en_curso_no_reprograma(): void
    {
        $credito = $this->credito();

        $this->pagar($credito, 80)->assertStatus(201)->assertJsonPath('abono_capital', '0.00');

        $this->assertDatabaseCount('reprogramaciones_credito', 0);
        $this->assertSame([80.0, 80.0, 0.0, EstadoCuota::Pendiente], $this->cuotas($credito)[1]);
    }
}
