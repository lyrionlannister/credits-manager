<?php

namespace Tests\Feature\Creditos;

use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Enums\TipoPago;
use App\Creditos\Models\Credito;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

/** `creditos:alinear-quincenas`: mueve al 15 y fin de mes solo las fechas de las cuotas sin pagos. */
class AlinearCuotasQuincenalesTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    /** Plan viejo de +15 días desde el 1 de enero de 2030: 16-ene, 31-ene, 15-feb, 2-mar, 17-mar. */
    private function creditoQuincenalViejo(array $cuotaUno = []): Credito
    {
        $credito = $this->crearCredito($this->crearCliente(), ['tipo_pago' => TipoPago::Quincenal, 'fecha_desembolso' => '2030-01-01', 'plazo' => 5]);

        foreach (['2030-01-16', '2030-01-31', '2030-02-15', '2030-03-02', '2030-03-17'] as $posicion => $fecha) {
            $this->crearCuota($credito, array_merge(
                ['numero_cuota' => $posicion + 1, 'fecha_vencimiento' => $fecha, 'valor' => 100, 'valor_capital' => 100],
                $posicion === 0 ? $cuotaUno : [],
            ));
        }

        return $credito;
    }

    private function fechas(Credito $credito): array
    {
        return $credito->cuotas()->orderBy('numero_cuota')->get()->map(fn ($cuota) => $cuota->fecha_vencimiento->toDateString())->all();
    }

    public function test_sin_empresa_o_con_una_inexistente_no_hace_nada(): void
    {
        $this->artisan('creditos:alinear-quincenas')->assertFailed();
        $this->artisan('creditos:alinear-quincenas --empresa=999999')->assertFailed();
    }

    public function test_por_defecto_solo_simula_y_no_guarda(): void
    {
        $admin = $this->actingAsAdmin();
        $credito = $this->creditoQuincenalViejo();
        $antes = $this->fechas($credito);

        $this->artisan("creditos:alinear-quincenas --empresa={$admin->empresa_id}")
            ->expectsOutputToContain('Es una simulación')
            ->assertSuccessful();

        $this->assertSame($antes, $this->fechas($credito));
    }

    public function test_con_aplicar_mueve_solo_las_fechas_sin_cambiar_valores(): void
    {
        $admin = $this->actingAsAdmin();
        $credito = $this->creditoQuincenalViejo();
        $valores = $credito->cuotas()->orderBy('numero_cuota')->pluck('valor')->map->centavos()->all();

        $this->artisan("creditos:alinear-quincenas --empresa={$admin->empresa_id} --aplicar")->assertSuccessful();

        $this->assertSame(['2030-01-15', '2030-01-31', '2030-02-15', '2030-02-28', '2030-03-15'], $this->fechas($credito));
        $this->assertSame($valores, $credito->cuotas()->orderBy('numero_cuota')->pluck('valor')->map->centavos()->all());
        $this->assertSame(5, $credito->cuotas()->where('estado', EstadoCuota::Pendiente)->count());
    }

    public function test_las_cuotas_pagadas_o_con_abonos_no_se_mueven_y_las_demas_siguen_desde_ellas(): void
    {
        $admin = $this->actingAsAdmin();
        $credito = $this->creditoQuincenalViejo(['valor_pagado' => 40]);

        $this->artisan("creditos:alinear-quincenas --empresa={$admin->empresa_id} --aplicar")->assertSuccessful();

        $this->assertSame(['2030-01-16', '2030-01-31', '2030-02-15', '2030-02-28', '2030-03-15'], $this->fechas($credito));
        $this->assertSame(40.0, $credito->cuotas()->where('numero_cuota', 1)->firstOrFail()->valor_pagado->aFloat());
    }

    public function test_es_idempotente(): void
    {
        $admin = $this->actingAsAdmin();
        $credito = $this->creditoQuincenalViejo();

        $this->artisan("creditos:alinear-quincenas --empresa={$admin->empresa_id} --aplicar")->assertSuccessful();
        $despues = $this->fechas($credito);

        $this->artisan("creditos:alinear-quincenas --empresa={$admin->empresa_id} --aplicar")
            ->expectsOutputToContain('No hay cuotas quincenales por alinear')
            ->assertSuccessful();
        $this->assertSame($despues, $this->fechas($credito));
    }

    public function test_una_fecha_que_ya_paso_queda_vencida(): void
    {
        $admin = $this->actingAsAdmin();
        $credito = $this->crearCredito($this->crearCliente(), ['tipo_pago' => TipoPago::Quincenal, 'fecha_desembolso' => '2020-01-01']);
        $this->crearCuota($credito, ['numero_cuota' => 1, 'fecha_vencimiento' => now()->addDays(3)->toDateString(), 'valor' => 100]);

        $this->artisan("creditos:alinear-quincenas --empresa={$admin->empresa_id} --aplicar")->assertSuccessful();

        $cuota = $credito->cuotas()->firstOrFail();
        $this->assertSame('2020-01-15', $cuota->fecha_vencimiento->toDateString());
        $this->assertSame(EstadoCuota::Vencida, $cuota->estado);
    }

    public function test_no_toca_creditos_mensuales_ni_de_otra_empresa(): void
    {
        $this->actingAsAdmin();
        $ajeno = $this->creditoQuincenalViejo();
        $fechasAjenas = $this->fechas($ajeno);

        $admin = $this->actingAsAdmin();
        $mensual = $this->crearCredito($this->crearCliente(), ['tipo_pago' => TipoPago::Mensual]);
        $this->crearCuota($mensual, ['fecha_vencimiento' => '2030-01-16']);
        $this->salirDeSesion();

        $this->artisan("creditos:alinear-quincenas --empresa={$admin->empresa_id} --aplicar")->assertSuccessful();

        $this->assertSame($fechasAjenas, $this->fechas($ajeno));
        $this->assertSame(['2030-01-16'], $this->fechas($mensual));
    }
}
