<?php

namespace Tests\Feature\Creditos;

use App\Creditos\Models\Credito;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

/** Los créditos migrados comparten `creado_en`: sin desempate por id, las páginas se repetían y otras se perdían. */
class ListarCreditosPaginacionTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    public function test_las_paginas_no_repiten_ni_pierden_creditos_con_la_misma_fecha_de_creacion(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        for ($i = 0; $i < 25; $i++) {
            $this->crearCredito($cliente);
        }
        Credito::query()->update(['creado_en' => '2026-10-19 06:49:23']);

        $vistos = [];
        foreach ([1, 2, 3] as $pagina) {
            $respuesta = $this->getJson("/api/v1/creditos?page={$pagina}&per_page=10")->assertOk();
            $vistos = array_merge($vistos, array_column($respuesta->json('data'), 'id'));
        }

        $this->assertCount(25, $vistos);
        $this->assertCount(25, array_unique($vistos));
        $this->assertSame(Credito::query()->orderByDesc('id')->pluck('id')->all(), $vistos);
    }
}
