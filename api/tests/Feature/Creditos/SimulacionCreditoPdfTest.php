<?php

namespace Tests\Feature\Creditos;

use App\Creditos\Enums\TipoCredito;
use App\Creditos\Enums\TipoPago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\AfirmaAuditoria;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

class SimulacionCreditoPdfTest extends TestCase
{
    use ActsAsAdmin;
    use AfirmaAuditoria;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/creditos/previsualizar/pdf';

    private function payload(int $clienteId): array
    {
        return [
            'cliente_id' => $clienteId,
            'tipo_credito' => TipoCredito::Nuevo->value,
            'valor_prestamo' => 22000000,
            'tasa_efectiva_anual' => 28.59,
            'gastos_administracion_pct' => 2,
            'plazo' => 4,
            'tipo_pago' => TipoPago::Quincenal->value,
            'fecha_desembolso' => '2026-11-20',
        ];
    }

    /** Descomprime los flujos del PDF y quita el escape de `(`, `)` y `\` de sus cadenas literales para buscar el texto dibujado. */
    private function textoDe(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $flujos);

        $texto = collect($flujos[1])->map(fn (string $flujo) => @gzuncompress($flujo) ?: '')->implode("\n");

        return strtr($texto, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\']);
    }

    public function test_rechaza_una_simulacion_sin_datos(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, [])->assertStatus(422)->assertJsonValidationErrors(['cliente_id', 'tasa_efectiva_anual', 'plazo', 'tipo_pago', 'fecha_desembolso']);
    }

    public function test_sin_autenticar_no_descarga_la_simulacion(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $this->salirDeSesion();

        $this->postJson(self::URL, $this->payload($cliente->id))->assertStatus(401);
    }

    public function test_sin_permiso_de_crear_creditos_no_descarga_la_simulacion(): void
    {
        $this->actingAsAsesor();
        $cliente = $this->crearCliente();

        $this->postJson(self::URL, $this->payload($cliente->id))->assertStatus(403);
    }

    public function test_un_cliente_inexistente_no_se_simula(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload(9999))->assertStatus(422)->assertJsonValidationErrors('cliente_id');
    }

    public function test_no_simula_para_un_cliente_de_otra_empresa(): void
    {
        $this->actingAsAdmin();
        $ajeno = $this->crearCliente();

        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->payload($ajeno->id))->assertStatus(422)->assertJsonValidationErrors('cliente_id');
        $this->assertSinEventosDeAuditoria('exportar');
    }

    public function test_descarga_la_simulacion_con_el_cliente_el_plan_y_las_condiciones_sin_crear_el_credito(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente(['nombre' => 'Maria Fernanda Lopez']);

        $respuesta = $this->post(self::URL, $this->payload($cliente->id), ['Accept' => 'application/pdf'])->assertOk();

        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('simulacion-credito-', $respuesta->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $respuesta->getContent());

        $texto = $this->textoDe($respuesta->getContent());
        foreach (['Simulaci', 'Maria Fernanda Lopez', 'Plan de cuotas', 'Total a pagar', '28.59 % EA (2.117600 % mensual)', '220,000.00'] as $esperado) {
            $this->assertTrue(str_contains($texto, $esperado), "No aparece «{$esperado}» en el PDF.");
        }

        $this->assertDatabaseCount('creditos', 0);
        $this->assertEventoAuditoria('exportar', 'creditos', 'cliente', $cliente->id);
    }
}
