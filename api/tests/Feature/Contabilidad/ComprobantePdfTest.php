<?php

namespace Tests\Feature\Contabilidad;

use App\Contabilidad\Models\Comprobante;
use App\Empresas\Models\Empresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\AfirmaAuditoria;
use Tests\Concerns\CreatesAuditoriaFixtures;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\LeeFlujosPdf;
use Tests\TestCase;

class ComprobantePdfTest extends TestCase
{
    use ActsAsAdmin;
    use AfirmaAuditoria;
    use CreatesAuditoriaFixtures;
    use CreatesContabilidadFixtures;
    use LeeFlujosPdf;
    use RefreshDatabase;

    /**
     * Crea un comprobante manual de 100, lo revisa y contabiliza otro usuario, y deja la sesión de ese revisor (con
     * permiso de imprimir y anular) en la empresa del admin. Devuelve su id.
     */
    private function crearComprobante(array $extra = []): int
    {
        $admin = $this->actingAsAdmin();
        $tipo = $this->crearTipoComprobante(['codigo' => 'CD']);
        $debito = $this->crearCuenta(['codigo' => '110505', 'nombre' => 'Caja general']);
        $credito = $this->crearCuenta(['codigo' => '413505', 'nombre' => 'Ingresos por intereses']);

        $id = $this->postJson('/api/v1/contabilidad/comprobantes', array_merge([
            'tipo_comprobante_id' => $tipo->id,
            'fecha' => '2026-01-15',
            'concepto' => 'Ajuste manual de prueba',
            'lineas' => [
                ['cuenta_id' => $debito->id, 'debito' => 100, 'credito' => 0, 'descripcion' => 'Entrada de caja'],
                ['cuenta_id' => $credito->id, 'debito' => 0, 'credito' => 100, 'descripcion' => 'Intereses del mes'],
            ],
        ], $extra))->assertCreated()->json('comprobante.id');

        $this->actingAsRolConPermisos('revisor', [
            'contabilidad.listar_comprobantes', 'contabilidad.revisar', 'contabilidad.contabilizar',
            'contabilidad.imprimir_comprobante', 'contabilidad.anular',
        ], Empresa::query()->findOrFail($admin->empresa_id));

        $this->postJson("/api/v1/contabilidad/comprobantes/{$id}/revisar")->assertOk();
        $this->postJson("/api/v1/contabilidad/comprobantes/{$id}/contabilizar")->assertOk();

        return $id;
    }

    /** Descomprime los flujos del PDF para poder buscar el texto dibujado. */
    private function textoDe(string $pdf): string
    {
        return $this->flujosDescomprimidos($pdf);
    }

    private function afirmarTexto(string $texto, array $esperados): void
    {
        foreach ($esperados as $esperado) {
            $this->assertTrue(str_contains($texto, $esperado), "No aparece «{$esperado}» en el PDF.");
        }
    }

    public function test_sin_autenticar_no_descarga_el_pdf(): void
    {
        $id = $this->crearComprobante();
        $this->salirDeSesion();

        $this->getJson("/api/v1/contabilidad/comprobantes/{$id}/pdf")->assertStatus(401);
    }

    public function test_sin_permiso_de_listar_no_descarga_el_pdf(): void
    {
        $id = $this->crearComprobante();
        $this->actingAsAsesor(Empresa::find(Comprobante::query()->findOrFail($id)->empresa_id));

        $this->getJson("/api/v1/contabilidad/comprobantes/{$id}/pdf")->assertStatus(403);
    }

    public function test_un_comprobante_inexistente_da_404(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/contabilidad/comprobantes/9999/pdf')->assertStatus(404);
    }

    public function test_un_admin_no_descarga_el_comprobante_de_otra_empresa(): void
    {
        $ajeno = $this->crearComprobante();

        $this->actingAsAdmin();

        $this->getJson("/api/v1/contabilidad/comprobantes/{$ajeno}/pdf")->assertStatus(404);
        $this->assertSinEventosDeAuditoria('exportar');
    }

    public function test_descarga_el_comprobante_con_sus_asientos_y_totales(): void
    {
        $id = $this->crearComprobante();

        $respuesta = $this->get("/api/v1/contabilidad/comprobantes/{$id}/pdf", ['Accept' => 'application/pdf'])->assertOk();

        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('comprobante-cd-', $respuesta->headers->get('Content-Disposition'));

        $texto = $this->textoDe($respuesta->getContent());

        $this->afirmarTexto($texto, [
            'Comprobante contable', 'No. CD ', 'Sin tercero asociado', 'Asientos contables', '110505 - Caja general', 'Entrada de caja',
            '413505 - Ingresos por intereses', 'Total d', 'Ajuste manual de prueba', 'Contabilizado', '100.00', 'Elaborado por',
        ]);
    }

    public function test_con_tercero_imprime_sus_datos_como_destinatario(): void
    {
        $this->actingAsAdmin();
        $tercero = $this->crearTercero(['nombre' => 'Proveedor Andino SAS']);
        $tipo = $this->crearTipoComprobante();
        $debito = $this->crearCuenta();
        $credito = $this->crearCuenta();

        $id = $this->postJson('/api/v1/contabilidad/comprobantes', [
            'tipo_comprobante_id' => $tipo->id, 'fecha' => '2026-02-01', 'concepto' => 'Compra', 'tercero_id' => $tercero->id,
            'documento_soporte' => 'FAC-77',
            'lineas' => [['cuenta_id' => $debito->id, 'debito' => 50, 'credito' => 0], ['cuenta_id' => $credito->id, 'debito' => 0, 'credito' => 50]],
        ])->assertCreated()->json('comprobante.id');

        $texto = $this->textoDe($this->get("/api/v1/contabilidad/comprobantes/{$id}/pdf")->assertOk()->getContent());

        $this->afirmarTexto($texto, ['Proveedor Andino SAS', 'Documento soporte', 'FAC-77']);
        $this->assertFalse(str_contains($texto, 'Sin tercero asociado'));
    }

    public function test_el_original_y_el_reverso_se_referencian_entre_si(): void
    {
        $id = $this->crearComprobante();
        $reversoId = $this->postJson("/api/v1/contabilidad/comprobantes/{$id}/reversar", ['motivo' => 'Error de digitacion'])->assertCreated()->json('comprobante.id');

        $original = $this->textoDe($this->get("/api/v1/contabilidad/comprobantes/{$id}/pdf")->assertOk()->getContent());
        $reverso = $this->textoDe($this->get("/api/v1/contabilidad/comprobantes/{$reversoId}/pdf")->assertOk()->getContent());

        $this->afirmarTexto($original, ['Reversado por', 'Anulado', 'Comprobante anulado']);
        $this->afirmarTexto($reverso, ['Reverso de', 'REVERSO - Error de digitacion']);
    }

    public function test_la_descarga_queda_en_la_bitacora_de_la_empresa(): void
    {
        $id = $this->crearComprobante();

        $this->get("/api/v1/contabilidad/comprobantes/{$id}/pdf")->assertOk();

        $evento = $this->assertEventoAuditoria('exportar', 'contabilidad', 'comprobante', $id);
        $this->assertNotNull($evento['empresa_id']);
    }
}
