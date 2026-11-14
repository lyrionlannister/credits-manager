<?php

namespace Tests\Feature\Creditos;

use App\Contabilidad\Enums\EventoContable;
use App\Core\Dinero\Dinero;
use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Models\Credito;
use App\Creditos\Models\Cuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\AfirmaAuditoria;
use Tests\Concerns\CreatesAuditoriaFixtures;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

class GastosAdministracionTest extends TestCase
{
    use ActsAsAdmin;
    use AfirmaAuditoria;
    use CreatesAuditoriaFixtures;
    use CreatesContabilidadFixtures;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    /** @param  array<string, mixed>  $cambios */
    private function crearCreditoConPorcentaje(?float $porcentaje, array $cambios = []): Credito
    {
        $datos = array_merge([
            'cliente_id' => $this->crearCliente()->id,
            'tipo_credito' => 'nuevo',
            'valor_prestamo' => 2500000,
            'tasa_interes' => 2,
            'plazo' => 3,
            'tipo_pago' => 'mensual',
            'fecha_desembolso' => '2026-01-01',
        ], $cambios);

        if ($porcentaje !== null) {
            $datos['gastos_administracion_pct'] = $porcentaje;
        }

        return Credito::findOrFail($this->postJson('/api/v1/creditos', $datos)->assertCreated()->json('credito.id'));
    }

    private function desembolsar(Credito $credito): Credito
    {
        $this->crearRegla(EventoContable::DesembolsoCredito);

        $this->postJson("/api/v1/creditos/{$credito->id}/aprobar")->assertOk();
        $this->postJson("/api/v1/creditos/{$credito->id}/desembolsar")->assertOk();

        return $credito->refresh();
    }

    /** @return list<array<string, int>> */
    private function cuotasComoLista(Credito $credito): array
    {
        return Cuota::where('credito_id', $credito->id)->orderBy('numero_cuota')->get()
            ->map(fn (Cuota $cuota) => [
                'capital' => $cuota->valor_capital->centavos(),
                'interes' => $cuota->valor_interes->centavos(),
                'valor' => $cuota->valor->centavos(),
                'otros' => $cuota->valor_otros_gastos->centavos(),
            ])->all();
    }

    public function test_el_valor_por_cuota_es_capital_inicial_por_porcentaje_y_es_igual_en_todas_las_cuotas(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCreditoConPorcentaje(0.2);

        $otros = Cuota::where('credito_id', $credito->id)->orderBy('numero_cuota')->get()
            ->map(fn (Cuota $cuota) => $cuota->valor_otros_gastos->centavos())
            ->all();

        $this->assertSame([500000, 500000, 500000], $otros);
        $this->assertSame('0.200', $credito->gastos_administracion_pct);
    }

    public function test_el_total_de_la_cuota_suma_capital_interes_y_otros_gastos(): void
    {
        $this->actingAsAdmin();
        $cuota = $this->crearCreditoConPorcentaje(0.2)->cuotas()->first();

        $this->assertSame($cuota->valor_capital->sumar($cuota->valor_interes)->sumar($cuota->valor_otros_gastos)->centavos(), $cuota->valor_total_cuota->centavos());
    }

    public function test_los_otros_gastos_no_cambian_capital_ni_interes_ni_valor_de_la_cuota(): void
    {
        $this->actingAsAdmin();
        $sinGastos = $this->crearCreditoConPorcentaje(0);
        $conGastos = $this->crearCreditoConPorcentaje(0.2);

        $sinOtros = fn (array $cuotas) => array_map(fn (array $c) => array_diff_key($c, ['otros' => true]), $cuotas);

        $this->assertSame($sinOtros($this->cuotasComoLista($sinGastos)), $sinOtros($this->cuotasComoLista($conGastos)));
    }

    public function test_la_cuota_total_del_credito_suma_la_cuota_fija_y_los_otros_gastos(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCreditoConPorcentaje(0.2);

        $this->getJson("/api/v1/creditos/{$credito->id}")
            ->assertOk()
            ->assertJsonPath('credito.valor_cuota_total', $credito->valor_cuota->sumar(Dinero::desdePesos(5000))->aDecimalString())
            ->assertJsonPath('credito.valor_total_con_otros', $credito->valor_total->sumar(Dinero::desdePesos(15000))->aDecimalString());
    }

    public function test_sin_porcentaje_el_credito_queda_en_cero(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCreditoConPorcentaje(null);

        $this->assertSame('0.000', $credito->gastos_administracion_pct);
        $this->assertTrue($credito->cuotas->every(fn (Cuota $cuota) => $cuota->valor_otros_gastos->esCero()));
    }

    public function test_la_refinanciacion_calcula_sobre_su_capital_inicial(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $origen = $this->desembolsar($this->crearCreditoConPorcentaje(null, ['cliente_id' => $cliente->id]));

        $refinanciacion = $this->postJson('/api/v1/creditos', [
            'cliente_id' => $cliente->id,
            'tipo_credito' => 'refinanciacion',
            'credito_origen_id' => $origen->id,
            'valor_adicional' => 500000,
            'saldo_refinanciado_previsualizado' => 2500000, // capital pendiente del origen: recién desembolsado, sin pagos.
            'tasa_interes' => 5,
            'plazo' => 3,
            'tipo_pago' => 'mensual',
            'fecha_desembolso' => '2026-04-01',
            'gastos_administracion_pct' => 0.2,
        ])->assertCreated()->json('credito.id');

        $capitalInicial = Credito::findOrFail($refinanciacion)->valor_prestamo;
        $esperado = $capitalInicial->multiplicarPorTasa(0.002)->centavos();

        $this->assertGreaterThan(0, $esperado);
        foreach (Cuota::where('credito_id', $refinanciacion)->get() as $cuota) {
            $this->assertSame($esperado, $cuota->valor_otros_gastos->centavos());
        }
    }

    public function test_editar_condiciones_de_un_credito_solicitado_guarda_el_porcentaje(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCreditoConPorcentaje(null);

        $this->patchJson("/api/v1/creditos/{$credito->id}", [
            'valor_prestamo' => 2500000,
            'tasa_interes' => 2,
            'plazo' => 3,
            'tipo_pago' => 'mensual',
            'fecha_desembolso' => '2026-01-01',
            'gastos_administracion_pct' => 0.2,
            'motivo' => 'Ajuste de gastos de administración',
        ])->assertOk();

        $this->assertSame('0.200', $credito->refresh()->gastos_administracion_pct);
        $this->assertTrue($credito->cuotas->every(fn (Cuota $cuota) => $cuota->valor_otros_gastos->centavos() === 500000));
    }

    public function test_editar_el_porcentaje_solo_recalcula_las_cuotas_no_pagadas(): void
    {
        $this->actingAsAdmin();
        $credito = $this->desembolsar($this->crearCreditoConPorcentaje(0.2));
        $primera = $credito->cuotas()->first();
        Cuota::where('id', $primera->id)->update(['estado' => EstadoCuota::Pagada->value, 'valor_pagado' => $primera->valor->centavos()]);
        $antes = $this->cuotasComoLista($credito);

        $this->patchJson("/api/v1/creditos/{$credito->id}/gastos-administracion", [
            'gastos_administracion_pct' => 1,
            'motivo' => 'Cambio de tarifa',
        ])->assertOk()->assertJsonPath('credito.gastos_administracion_pct', '1.000');

        $despues = $this->cuotasComoLista($credito);
        $sinOtros = fn (array $cuotas) => array_map(fn (array $c) => array_diff_key($c, ['otros' => true]), $cuotas);

        $this->assertSame($antes[0]['otros'], $despues[0]['otros'], 'La cuota pagada no cambia.');
        $this->assertSame(2500000, $despues[1]['otros']);
        $this->assertSame(2500000, $despues[2]['otros']);
        $this->assertSame($sinOtros($antes), $sinOtros($despues), 'Capital, interés y valor no cambian: no se regenera la amortización.');
    }

    public function test_editar_el_porcentaje_deja_el_motivo_en_la_bitacora(): void
    {
        $this->actingAsAdmin();
        $credito = $this->desembolsar($this->crearCreditoConPorcentaje(0.2));

        $this->patchJson("/api/v1/creditos/{$credito->id}/gastos-administracion", [
            'gastos_administracion_pct' => 0.5,
            'motivo' => 'Cambio de tarifa',
        ])->assertOk();

        $evento = $this->assertEventoAuditoria('modificar', 'creditos', 'credito', $credito->id);

        $this->assertSame('Cambio de tarifa', $evento['motivo']);
        $this->assertSame('0.200', $evento['valores_anteriores']['gastos_administracion_pct']);
        $this->assertSame('0.500', $evento['valores_nuevos']['gastos_administracion_pct']);
    }

    public function test_no_permite_cambiar_el_porcentaje_de_un_credito_no_desembolsado(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCreditoConPorcentaje(0.2);

        $this->patchJson("/api/v1/creditos/{$credito->id}/gastos-administracion", [
            'gastos_administracion_pct' => 1,
            'motivo' => 'Cambio',
        ])->assertStatus(422)->assertJsonValidationErrors('estado');
    }

    public function test_rechaza_un_porcentaje_fuera_de_rango_o_sin_motivo(): void
    {
        $this->actingAsAdmin();
        $credito = $this->desembolsar($this->crearCreditoConPorcentaje(0.2));

        $this->patchJson("/api/v1/creditos/{$credito->id}/gastos-administracion", [
            'gastos_administracion_pct' => -1,
            'motivo' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['gastos_administracion_pct', 'motivo']);

        $this->patchJson("/api/v1/creditos/{$credito->id}/gastos-administracion", [
            'gastos_administracion_pct' => 101,
            'motivo' => 'Cambio',
        ])->assertStatus(422)->assertJsonValidationErrors('gastos_administracion_pct');
    }

    public function test_rechaza_cambiar_el_porcentaje_sin_autenticar(): void
    {
        $this->actingAsAdmin();
        $credito = $this->desembolsar($this->crearCreditoConPorcentaje(0.2));
        $this->salirDeSesion();

        $this->patchJson("/api/v1/creditos/{$credito->id}/gastos-administracion", [
            'gastos_administracion_pct' => 1,
            'motivo' => 'Cambio',
        ])->assertStatus(401);
    }

    public function test_un_asesor_sin_el_permiso_no_puede_cambiar_el_porcentaje(): void
    {
        $empresa = $this->crearEmpresaDePrueba();
        $this->actingAsAdmin($empresa);
        $credito = $this->desembolsar($this->crearCreditoConPorcentaje(0.2));
        $this->actingAsAsesor($empresa)->update(['es_asesor_global' => true]);

        $this->patchJson("/api/v1/creditos/{$credito->id}/gastos-administracion", [
            'gastos_administracion_pct' => 1,
            'motivo' => 'Cambio',
        ])->assertStatus(403);

        $this->assertDatabaseHas('cuotas', ['credito_id' => $credito->id, 'valor_otros_gastos' => 500000]);
    }

    public function test_no_cambia_el_porcentaje_de_un_credito_de_otra_empresa(): void
    {
        $this->actingAsAdmin();
        $ajeno = $this->desembolsar($this->crearCreditoConPorcentaje(0.2));

        $this->actingAsAdmin();
        $this->patchJson("/api/v1/creditos/{$ajeno->id}/gastos-administracion", [
            'gastos_administracion_pct' => 1,
            'motivo' => 'Intento ajeno',
        ])->assertStatus(404);

        $this->assertDatabaseHas('creditos', ['id' => $ajeno->id, 'gastos_administracion_pct' => 0.2]);
    }
}
