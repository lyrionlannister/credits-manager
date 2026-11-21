<?php

namespace Tests\Feature\Impresion;

use App\Core\Dinero\Dinero;
use App\Empresas\Models\Empresa;
use App\Geografia\Models\Estado;
use App\Geografia\Models\Municipio;
use App\Geografia\Models\Pais;
use App\Impresion\Data\ColumnaDocumento;
use App\Impresion\Data\DestinatarioDocumento;
use App\Impresion\Data\TablaDocumento;
use App\Impresion\Enums\OrientacionDocumento;
use App\Impresion\Enums\TipoColumnaDocumento;
use App\Impresion\Services\GeneradorPdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\LeeFlujosPdf;
use Tests\Support\DocumentoPdfDePrueba;
use Tests\TestCase;

class GeneradorPdfTest extends TestCase
{
    use LeeFlujosPdf;

    private function empresa(array $atributos = []): Empresa
    {
        $empresa = new Empresa(array_merge([
            'nombre_empresa' => 'DDA Inversiones SAS', 'nit' => '901.687.377-8', 'direccion' => 'CR 77 18 5',
            'telefono' => '3013731468', 'correo' => 'contacto@dda.test',
        ], $atributos));

        $municipio = (new Municipio(['nombre' => 'Bogota']))->setRelation(
            'estado',
            (new Estado)->setRelation('pais', new Pais(['nombre' => 'Colombia'])),
        );

        return $empresa->setRelation('municipio', $municipio);
    }

    private function generar(?array $filas = null, ?Empresa $empresa = null, ?string $consecutivo = 'DDA 186'): string
    {
        return app(GeneradorPdf::class)->generar(new DocumentoPdfDePrueba($filas, $consecutivo), $empresa ?? $this->empresa());
    }

    /** Descomprime los flujos del PDF para poder buscar el texto dibujado. */
    private function textoDe(string $pdf): string
    {
        return $this->flujosDescomprimidos($pdf);
    }

    private function paginasDe(string $pdf): int
    {
        preg_match('/\/Type\s*\/Pages.*?\/Count\s+(\d+)/s', $pdf, $coincidencia);

        return (int) ($coincidencia[1] ?? 0);
    }

    public function test_genera_un_pdf_en_carta_vertical(): void
    {
        $pdf = $this->generar();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[0(\.0+)?\s+0(\.0+)?\s+612(\.0+)?\s+792(\.0+)?\]/', $pdf);
    }

    public function test_dibuja_la_empresa_el_destinatario_y_los_totales_de_la_plantilla(): void
    {
        $texto = $this->textoDe($this->generar());

        foreach (['DDA INVERSIONES SAS', 'NIT 901.687.377-8', 'Bogota - Colombia', 'Documento de prueba', 'No. DDA 186', 'CLIENTE DE PRUEBA S.A.S.', '15/07/2026', '1,222,380.00', 'Total a Pagar', 'INTERES.AMORT.ADM'] as $esperado) {
            $this->assertTrue(str_contains($texto, $esperado), "No aparece «{$esperado}» en el PDF.");
        }
    }

    public function test_un_documento_sin_consecutivo_no_imprime_el_numero(): void
    {
        $texto = $this->textoDe($this->generar(consecutivo: null));

        $this->assertStringNotContainsString('No. ', $texto);
    }

    public function test_un_documento_largo_ocupa_varias_paginas_con_el_encabezado_repetido(): void
    {
        $filas = [];

        for ($i = 1; $i <= 80; $i++) {
            $filas[] = ['item' => $i, 'descripcion' => "Cuota {$i}", 'cantidad' => 1, 'valor' => '1000.00'];
        }

        $pdf = $this->generar($filas);
        $texto = $this->textoDe($pdf);

        $this->assertGreaterThan(1, $this->paginasDe($pdf));
        $this->assertSame(1, substr_count($texto, "Total \xEDtems"), 'El resumen y los totales van una sola vez, al final.');
        $this->assertGreaterThan(1, substr_count($texto, 'Vr. Total'));
    }

    public function test_un_documento_horizontal_sale_en_carta_apaisada(): void
    {
        $pdf = app(GeneradorPdf::class)->generar(new DocumentoPdfDePrueba(orientacion: OrientacionDocumento::Horizontal), $this->empresa());

        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[0(\.0+)?\s+0(\.0+)?\s+792(\.0+)?\s+612(\.0+)?\]/', $pdf);
    }

    public function test_imprime_el_titulo_de_la_tabla_principal_y_las_tablas_adicionales_con_su_total(): void
    {
        $pagos = new TablaDocumento(
            'Detalle de pagos',
            [
                new ColumnaDocumento('fecha', 'Fecha', TipoColumnaDocumento::Fecha),
                new ColumnaDocumento('valor', 'Valor', TipoColumnaDocumento::Monetario),
            ],
            [['fecha' => CarbonImmutable::parse('2026-03-05'), 'valor' => Dinero::desdePesos(250000)]],
            ['fecha' => 'Total pagado', 'valor' => Dinero::desdePesos(250000)],
        );
        $sinPagos = new TablaDocumento('Otros cobros', [new ColumnaDocumento('concepto', 'Concepto')], [], null, 'Aun no hay cobros.');

        $texto = $this->textoDe(app(GeneradorPdf::class)->generar(
            new DocumentoPdfDePrueba(tablasAdicionales: [$pagos, $sinPagos], tituloTabla: 'Plan de cuotas'),
            $this->empresa(),
        ));

        foreach (['Plan de cuotas', 'Detalle de pagos', '05/03/2026', '250,000.00', 'Total pagado', 'Otros cobros', 'Aun no hay cobros.'] as $esperado) {
            $this->assertTrue(str_contains($texto, $esperado), "No aparece «{$esperado}» en el PDF.");
        }
    }

    public function test_la_etiqueta_del_documento_del_destinatario_se_puede_cambiar(): void
    {
        $destinatario = new DestinatarioDocumento('Ana Perez', 'CC 123456', etiquetaDocumento: 'Documento');

        $this->assertSame('Documento', $destinatario->etiquetaDocumento);
        $this->assertSame('NIT', (new DestinatarioDocumento('Empresa SAS', '900'))->etiquetaDocumento);
    }

    public function test_incrusta_el_logo_cuando_es_una_imagen(): void
    {
        Storage::fake('public');
        $imagen = imagecreatetruecolor(60, 30);
        ob_start();
        imagepng($imagen);
        Storage::disk('public')->put('logos/logo.png', ob_get_clean());

        $conLogo = $this->generar(empresa: $this->empresa(['logo_path' => 'storage/logos/logo.png']));
        $sinLogo = $this->generar();

        $this->assertStringContainsString('/Subtype /Image', $conLogo);
        $this->assertStringNotContainsString('/Subtype /Image', $sinLogo);
    }

    public function test_un_logo_svg_o_inexistente_no_rompe_el_pdf_y_no_se_dibuja(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>');

        $svg = $this->generar(empresa: $this->empresa(['logo_path' => 'storage/logos/logo.svg']));
        $faltante = $this->generar(empresa: $this->empresa(['logo_path' => 'storage/logos/no-existe.png']));

        $this->assertStringStartsWith('%PDF-', $svg);
        $this->assertStringNotContainsString('/Subtype /Image', $svg);
        $this->assertStringStartsWith('%PDF-', $faltante);
        $this->assertStringNotContainsString('/Subtype /Image', $faltante);
    }
}
