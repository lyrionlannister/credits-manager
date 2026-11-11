<?php

namespace Tests\Feature\Cartera;

use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Models\Credito;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

/** Cuánto se debe en mora: lo que falta de las cuotas vencidas y los días desde la primera. Sin interés de mora. */
class MontoEnMoraTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    private function creditoConDosVencidas(): Credito
    {
        $credito = $this->crearCredito($this->crearCliente());
        $this->crearCuota($credito, ['numero_cuota' => 1, 'fecha_vencimiento' => now()->subDays(40)->toDateString(), 'valor' => 300, 'valor_pagado' => 100, 'estado' => EstadoCuota::Vencida]);
        $this->crearCuota($credito, ['numero_cuota' => 2, 'fecha_vencimiento' => now()->subDays(10)->toDateString(), 'valor' => 300, 'estado' => EstadoCuota::Vencida]);
        $this->crearCuota($credito, ['numero_cuota' => 3, 'fecha_vencimiento' => now()->addDays(20)->toDateString(), 'valor' => 300]);

        return $credito;
    }

    public function test_el_detalle_del_credito_muestra_el_monto_vencido_y_los_dias_de_mora(): void
    {
        $this->actingAsAdmin();
        $credito = $this->creditoConDosVencidas();

        $this->getJson("/api/v1/creditos/{$credito->id}")
            ->assertOk()
            ->assertJsonPath('mora.monto_vencido', '500.00')
            ->assertJsonPath('mora.cuotas_vencidas', 2)
            ->assertJsonPath('mora.dias', 40);
    }

    public function test_un_credito_al_dia_no_tiene_mora(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCredito($this->crearCliente());
        $this->crearCuota($credito, ['fecha_vencimiento' => now()->addDays(20)->toDateString()]);

        $this->getJson("/api/v1/creditos/{$credito->id}")
            ->assertOk()
            ->assertJsonPath('mora.monto_vencido', '0.00')
            ->assertJsonPath('mora.dias', 0);
    }

    public function test_la_cartera_en_mora_trae_el_monto_vencido_de_cada_credito(): void
    {
        $this->actingAsAdmin();
        $credito = $this->creditoConDosVencidas();

        $this->getJson('/api/v1/cartera')
            ->assertOk()
            ->assertJsonPath('en_mora.0.credito.id', $credito->id)
            ->assertJsonPath('en_mora.0.monto_vencido', '500.00')
            ->assertJsonPath('en_mora.0.cuotas_vencidas', 2)
            ->assertJsonPath('en_mora.0.dias_mora', 40);
    }
}
