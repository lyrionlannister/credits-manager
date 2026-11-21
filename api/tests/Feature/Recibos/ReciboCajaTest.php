<?php

namespace Tests\Feature\Recibos;

use App\Contabilidad\Enums\EventoContable;
use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Models\Credito;
use App\Empresas\Models\Empresa;
use App\Pagos\Models\Pago;
use App\Recibos\Models\ReciboCaja;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\AfirmaAuditoria;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\Concerns\LeeFlujosPdf;
use Tests\TestCase;

class ReciboCajaTest extends TestCase
{
    use ActsAsAdmin;
    use AfirmaAuditoria;
    use CreatesContabilidadFixtures;
    use CreatesCreditFixtures;
    use LeeFlujosPdf;
    use RefreshDatabase;

    /** Crédito desembolsado con dos cuotas de 300; deja la sesión del admin de su empresa. */
    private function creditoConCuotas(string $nombreCliente = 'Maria Fernanda Lopez'): Credito
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $credito = $this->crearCredito($this->crearCliente(['nombre' => $nombreCliente]));
        $this->crearCuota($credito, ['numero_cuota' => 1, 'valor' => 300]);
        $this->crearCuota($credito, ['numero_cuota' => 2, 'valor' => 300, 'fecha_vencimiento' => '2026-03-01']);

        return $credito;
    }

    private function pagar(int $creditoId, int $valor = 100): int
    {
        return $this->postJson("/api/v1/creditos/{$creditoId}/pagos", ['valor_pago' => $valor, 'forma_pago' => 'transferencia'])->assertCreated()->json('pago.id');
    }

    /** Descomprime los flujos del PDF para poder buscar el texto dibujado. */
    private function textoDe(string $pdf): string
    {
        return $this->flujosDescomprimidos($pdf);
    }

    public function test_cada_pago_recibe_su_recibo_numerado_en_orden(): void
    {
        $credito = $this->creditoConCuotas();

        $primero = $this->pagar($credito->id);
        $segundo = $this->pagar($credito->id);

        $this->assertDatabaseHas('recibos_caja', ['pago_id' => $primero, 'consecutivo' => 1, 'numero' => 'RC 1', 'estado' => 'emitido', 'empresa_id' => $credito->empresa_id]);
        $this->assertDatabaseHas('recibos_caja', ['pago_id' => $segundo, 'consecutivo' => 2, 'numero' => 'RC 2', 'estado' => 'emitido']);
        $this->assertDatabaseHas('consecutivos_documento', ['empresa_id' => $credito->empresa_id, 'tipo_documento' => 'recibo_caja', 'prefijo' => 'RC', 'ultimo_numero' => 2]);
    }

    public function test_cada_empresa_lleva_su_propio_consecutivo(): void
    {
        $a = $this->creditoConCuotas();
        $this->pagar($a->id);
        $this->pagar($a->id);

        $b = $this->creditoConCuotas();
        $pagoB = $this->pagar($b->id);

        $this->assertDatabaseHas('recibos_caja', ['pago_id' => $pagoB, 'numero' => 'RC 1', 'empresa_id' => $b->empresa_id]);
        $this->assertSame(2, ReciboCaja::query()->withoutGlobalScopes()->where('empresa_id', $a->empresa_id)->count());
    }

    public function test_un_pago_que_falla_no_consume_el_consecutivo(): void
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $sinDesembolso = $this->crearCredito($this->crearCliente(), ['estado' => EstadoCredito::Solicitado]);

        $this->postJson("/api/v1/creditos/{$sinDesembolso->id}/pagos", ['valor_pago' => 100, 'forma_pago' => 'efectivo'])->assertStatus(422);
        $this->assertDatabaseCount('recibos_caja', 0);

        $credito = $this->crearCredito($this->crearCliente());
        $this->crearCuota($credito);
        $pago = $this->pagar($credito->id);

        $this->assertDatabaseHas('recibos_caja', ['pago_id' => $pago, 'numero' => 'RC 1']);
    }

    public function test_la_liquidacion_anticipada_tambien_emite_recibo(): void
    {
        $credito = $this->creditoConCuotas();

        $pagoId = $this->postJson("/api/v1/creditos/{$credito->id}/liquidacion", ['forma_pago' => 'efectivo'])->assertSuccessful()->json('pago.id');

        $this->assertDatabaseHas('recibos_caja', ['pago_id' => $pagoId, 'numero' => 'RC 1']);
    }

    public function test_el_recibo_enlaza_el_comprobante_contable_del_pago(): void
    {
        $this->actingAsAdmin();
        $this->crearRegla(EventoContable::RecaudoCapital);
        $this->crearRegla(EventoContable::RecaudoInteres);
        $credito = $this->crearCredito($this->crearCliente());
        $this->crearCuota($credito);

        $pagoId = $this->pagar($credito->id);

        $recibo = ReciboCaja::query()->where('pago_id', $pagoId)->firstOrFail();
        $comprobanteId = DB::table('comprobantes')->where('modulo_origen', 'pagos')->where('origen_id', $pagoId)->value('id');

        $this->assertSame($comprobanteId, $recibo->comprobante_id);
    }

    public function test_emitir_el_recibo_queda_en_la_bitacora(): void
    {
        $credito = $this->creditoConCuotas();
        $pagoId = $this->pagar($credito->id);

        $evento = $this->assertEventoAuditoria('crear', 'pagos', 'recibo_caja');

        $this->assertSame('RC 1', $evento['valores_nuevos']['numero']);
        $this->assertSame($pagoId, $evento['valores_nuevos']['pago_id']);
    }

    public function test_la_base_rechaza_un_recibo_de_una_empresa_sobre_el_pago_de_otra(): void
    {
        $credito = $this->creditoConCuotas();
        $pagoId = $this->pagar($credito->id);
        $otraEmpresa = $this->crearEmpresaDePrueba();

        $this->expectException(QueryException::class);

        DB::table('recibos_caja')->insert([
            'empresa_id' => $otraEmpresa->id, 'pago_id' => $pagoId, 'consecutivo' => 99, 'numero' => 'RC 99', 'estado' => 'emitido',
        ]);
    }

    public function test_sin_autenticar_no_descarga_el_recibo(): void
    {
        $credito = $this->creditoConCuotas();
        $pagoId = $this->pagar($credito->id);
        $this->salirDeSesion();

        $this->getJson("/api/v1/pagos/{$pagoId}/recibo/pdf")->assertStatus(401);
    }

    public function test_sin_permiso_de_listar_pagos_no_descarga_el_recibo(): void
    {
        $credito = $this->creditoConCuotas();
        $pagoId = $this->pagar($credito->id);
        $this->actingAsAsesor(Empresa::find($credito->empresa_id))->update(['es_asesor_global' => true]);

        $this->getJson("/api/v1/pagos/{$pagoId}/recibo/pdf")->assertStatus(403);
    }

    public function test_un_pago_inexistente_da_404(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/pagos/9999/recibo/pdf')->assertStatus(404);
    }

    public function test_un_admin_no_descarga_el_recibo_de_otra_empresa(): void
    {
        $credito = $this->creditoConCuotas();
        $ajeno = $this->pagar($credito->id);

        $this->actingAsAdmin();

        $this->getJson("/api/v1/pagos/{$ajeno}/recibo/pdf")->assertStatus(404);
        $this->assertSinEventosDeAuditoria('exportar');
    }

    public function test_descarga_el_recibo_en_pdf_con_el_cliente_el_valor_y_el_asiento(): void
    {
        $this->actingAsAdmin();
        $this->crearRegla(EventoContable::RecaudoCapital);
        $this->crearRegla(EventoContable::RecaudoInteres);
        $credito = $this->crearCredito($this->crearCliente(['nombre' => 'Maria Fernanda Lopez']));
        $this->crearCuota($credito, ['numero_cuota' => 1, 'valor' => 300]);
        $pagoId = $this->pagar($credito->id, 250);

        $respuesta = $this->get("/api/v1/pagos/{$pagoId}/recibo/pdf", ['Accept' => 'application/pdf'])->assertOk();

        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('recibo-de-caja-rc-1.pdf', $respuesta->headers->get('Content-Disposition'));

        $texto = $this->textoDe($respuesta->getContent());

        foreach (['Recibo de caja', 'No. RC 1', 'Maria Fernanda Lopez', 'Cuota 1 del cr', 'Total recibido', '250.00', 'Doscientos cincuenta pesos m/cte', 'Transferencia', 'Cuenta de caja o banco', 'Comprobante contable', 'Recibido por'] as $esperado) {
            $this->assertTrue(str_contains($texto, $esperado), "No aparece «{$esperado}» en el PDF.");
        }
    }

    public function test_el_recibo_de_una_liquidacion_imprime_el_pago_completo_en_una_linea(): void
    {
        $credito = $this->creditoConCuotas();
        $pagoId = $this->postJson("/api/v1/creditos/{$credito->id}/liquidacion", ['forma_pago' => 'efectivo'])->assertSuccessful()->json('pago.id');

        $texto = $this->textoDe($this->get("/api/v1/pagos/{$pagoId}/recibo/pdf")->assertOk()->getContent());

        $this->assertTrue(str_contains($texto, "Pago del cr\xE9dito #{$credito->id}"));
        $this->assertTrue(str_contains($texto, 'Liquidaci'));
    }

    public function test_un_recibo_con_todas_sus_secciones_cabe_en_una_hoja(): void
    {
        $this->actingAsAdmin();
        $this->crearRegla(EventoContable::RecaudoCapital);
        $this->crearRegla(EventoContable::RecaudoInteres);
        $credito = $this->crearCredito($this->crearCliente());
        $this->crearCuota($credito);
        $pagoId = $this->pagar($credito->id, 250);
        Pago::query()->findOrFail($pagoId)->update(['observacion' => 'Pago recibido en ventanilla']);

        $pdf = $this->get("/api/v1/pagos/{$pagoId}/recibo/pdf")->assertOk()->getContent();

        preg_match('/\/Type\s*\/Pages.*?\/Count\s+(\d+)/s', $pdf, $paginas);
        $this->assertSame('1', $paginas[1], 'El recibo con todas sus secciones debe caber en una sola hoja.');
    }

    public function test_la_descarga_del_recibo_queda_en_la_bitacora_de_la_empresa(): void
    {
        $credito = $this->creditoConCuotas();
        $pagoId = $this->pagar($credito->id);
        $reciboId = Pago::query()->findOrFail($pagoId)->recibo->id;

        $this->get("/api/v1/pagos/{$pagoId}/recibo/pdf")->assertOk();

        $evento = $this->assertEventoAuditoria('exportar', 'pagos', 'recibo_caja', $reciboId);
        $this->assertSame($credito->empresa_id, $evento['empresa_id']);
    }
}
