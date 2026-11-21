<?php

namespace Tests\Feature\Creditos;

use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Models\Credito;
use App\Empresas\Models\Empresa;
use App\Pagos\Enums\FormaPago;
use App\Pagos\Models\Pago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\AfirmaAuditoria;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\Concerns\LeeFlujosPdf;
use Tests\TestCase;

class EstadoCuentaPdfTest extends TestCase
{
    use ActsAsAdmin;
    use AfirmaAuditoria;
    use CreatesCreditFixtures;
    use LeeFlujosPdf;
    use RefreshDatabase;

    /** Un crédito con una cuota pagada, una pendiente y un pago; devuelve el crédito ya con la sesión del admin de su empresa. */
    private function creditoConPagos(): Credito
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente(['nombre' => 'Maria Fernanda Lopez']);
        $credito = $this->crearCredito($cliente);
        $this->crearCuota($credito, ['numero_cuota' => 1, 'estado' => EstadoCuota::Pagada, 'valor' => 300, 'valor_pagado' => 300]);
        $this->crearCuota($credito, ['numero_cuota' => 2, 'estado' => EstadoCuota::Pendiente, 'valor' => 300, 'fecha_vencimiento' => '2026-03-01']);
        Pago::create([
            'credito_id' => $credito->id, 'cliente_id' => $cliente->id, 'valor_pago' => 300, 'fecha_pago' => '2026-02-01',
            'forma_pago' => FormaPago::Transferencia, 'observacion' => 'Abono de febrero',
        ]);

        return $credito;
    }

    /** Descomprime los flujos del PDF para poder buscar el texto dibujado. */
    private function textoDe(string $pdf): string
    {
        return $this->flujosDescomprimidos($pdf);
    }

    public function test_sin_autenticar_no_descarga_el_pdf(): void
    {
        $credito = $this->creditoConPagos();
        $this->salirDeSesion();

        $this->getJson("/api/v1/creditos/{$credito->id}/estado-cuenta/pdf")->assertStatus(401);
    }

    public function test_sin_permiso_de_listar_creditos_no_descarga_el_pdf(): void
    {
        $credito = $this->creditoConPagos();
        $this->actingAsAsesor(Empresa::find($credito->empresa_id))->update(['es_asesor_global' => true]);

        $this->getJson("/api/v1/creditos/{$credito->id}/estado-cuenta/pdf")->assertStatus(403);
    }

    public function test_un_credito_inexistente_da_404(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/creditos/9999/estado-cuenta/pdf')->assertStatus(404);
    }

    public function test_un_admin_no_descarga_el_estado_de_cuenta_de_otra_empresa(): void
    {
        $ajeno = $this->creditoConPagos();

        $this->actingAsAdmin();

        $this->getJson("/api/v1/creditos/{$ajeno->id}/estado-cuenta/pdf")->assertStatus(404);
        $this->assertSinEventosDeAuditoria('exportar');
    }

    public function test_descarga_el_estado_de_cuenta_en_pdf_con_el_cliente_las_cuotas_y_los_pagos(): void
    {
        $credito = $this->creditoConPagos();

        $respuesta = $this->get("/api/v1/creditos/{$credito->id}/estado-cuenta/pdf", ['Accept' => 'application/pdf'])->assertOk();

        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString("estado-de-cuenta-credito-{$credito->id}.pdf", $respuesta->headers->get('Content-Disposition'));

        $pdf = $respuesta->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);

        $texto = $this->textoDe($pdf);

        foreach (['Estado de cuenta', 'Maria Fernanda Lopez', 'CC', 'Plan de cuotas', 'Detalle de pagos', 'Abono de febrero', 'Total pagado', 'Saldo pendiente', 'Cuotas pagadas / pactadas: 1 / 3', '01/03/2026'] as $esperado) {
            $this->assertTrue(str_contains($texto, $esperado), "No aparece «{$esperado}» en el PDF.");
        }
    }

    public function test_un_credito_sin_pagos_lo_dice_en_la_tabla(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCredito($this->crearCliente());
        $this->crearCuota($credito);

        $texto = $this->textoDe($this->get("/api/v1/creditos/{$credito->id}/estado-cuenta/pdf")->assertOk()->getContent());

        $this->assertStringContainsString("A\xFAn no se han registrado pagos.", $texto);
    }

    public function test_la_descarga_queda_en_la_bitacora_de_la_empresa(): void
    {
        $credito = $this->creditoConPagos();

        $this->get("/api/v1/creditos/{$credito->id}/estado-cuenta/pdf")->assertOk();

        $evento = $this->assertEventoAuditoria('exportar', 'creditos', 'credito', $credito->id);
        $this->assertSame($credito->empresa_id, $evento['empresa_id']);
    }
}
