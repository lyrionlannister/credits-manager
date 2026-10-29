<?php

namespace App\Facturacion\Documentos;

use App\Core\Dinero\Dinero;
use App\Facturacion\Enums\TipoDocumentoFacturacion;
use App\Facturacion\Models\DocumentoFacturacion;
use App\Impresion\Contracts\DocumentoPdf;
use App\Impresion\Data\CamposEmpresaDocumento;
use App\Impresion\Data\ColumnaDocumento;
use App\Impresion\Data\DestinatarioDocumento;
use App\Impresion\Data\FechaDocumento;
use App\Impresion\Data\SeccionDocumento;
use App\Impresion\Data\TotalDocumento;
use App\Impresion\Enums\OrientacionDocumento;
use App\Impresion\Enums\TipoColumnaDocumento;
use App\Impresion\Services\NumeroALetras;
use Illuminate\Support\Str;

/**
 * Factura o nota de un documento emitido. Espera el documento con `lineas`, `cliente.tercero`, `credito` y `origen` cargados,
 * y los totales ya calculados por el Service (para no recalcular aquí).
 */
final class FacturaPdf implements DocumentoPdf
{
    /** @param array{total: Dinero, total_nota_credito: Dinero, total_nota_debito: Dinero, total_cobrado: Dinero, saldo: Dinero, saldo_a_favor: Dinero} $totales */
    public function __construct(
        private readonly DocumentoFacturacion $documento,
        private readonly array $totales,
    ) {}

    public function titulo(): string
    {
        return $this->documento->tipo->etiqueta();
    }

    public function consecutivo(): ?string
    {
        return $this->documento->numero;
    }

    public function nombreArchivo(): string
    {
        return Str::slug($this->titulo()).'-'.Str::slug($this->documento->numero);
    }

    public function orientacion(): OrientacionDocumento
    {
        return OrientacionDocumento::Vertical;
    }

    public function destinatario(): DestinatarioDocumento
    {
        return DestinatarioDocumento::desdeTercero($this->documento->cliente->tercero);
    }

    public function fechas(): array
    {
        return [new FechaDocumento('Fecha', $this->documento->fecha, true)];
    }

    public function tituloTabla(): ?string
    {
        return null;
    }

    public function columnas(): array
    {
        return [
            new ColumnaDocumento('item', 'Ítem', TipoColumnaDocumento::Entero, 8),
            new ColumnaDocumento('concepto', 'Concepto', TipoColumnaDocumento::Texto, 30),
            new ColumnaDocumento('descripcion', 'Descripción', TipoColumnaDocumento::Texto, 42),
            new ColumnaDocumento('valor', 'Valor', TipoColumnaDocumento::Monetario, 20),
        ];
    }

    public function filas(): iterable
    {
        foreach ($this->documento->lineas as $indice => $linea) {
            yield [
                'item' => $indice + 1,
                'concepto' => $linea->concepto,
                'descripcion' => $linea->descripcion,
                'valor' => $linea->valor,
            ];
        }
    }

    public function tablasAdicionales(): array
    {
        return [];
    }

    public function totales(): array
    {
        // Subtotal/IVA/retención solo si hay algo que mostrar: con impuestos en cero (el caso de hoy), el total basta.
        $hayImpuestos = ($this->totales['total_iva'] ?? null)?->esPositivo() || ($this->totales['total_retencion'] ?? null)?->esPositivo();

        $totales = $hayImpuestos
            ? [
                new TotalDocumento('Subtotal', $this->totales['subtotal']),
                new TotalDocumento('IVA', $this->totales['total_iva']),
                new TotalDocumento('Retenciones', $this->totales['total_retencion']),
                new TotalDocumento('Total', $this->totales['total']),
            ]
            : [new TotalDocumento('Total', $this->totales['total'])];

        if ($this->documento->tipo === TipoDocumentoFacturacion::Factura) {
            $totales[] = new TotalDocumento('Notas crédito', $this->totales['total_nota_credito']);
            $totales[] = new TotalDocumento('Notas débito', $this->totales['total_nota_debito']);
            $totales[] = new TotalDocumento('Cobros', $this->totales['total_cobrado']);
            $totales[] = new TotalDocumento('Saldo', $this->totales['saldo']);

            if ($this->totales['saldo_a_favor']->esPositivo()) {
                $totales[] = new TotalDocumento('Saldo a favor del cliente', $this->totales['saldo_a_favor']);
            }
        }

        return $totales;
    }

    public function secciones(): array
    {
        $secciones = [new SeccionDocumento('Valor en letras', NumeroALetras::pesos($this->totales['total']))];

        $secciones[] = new SeccionDocumento('Crédito', (string) $this->documento->credito_id);

        if ($this->documento->origen) {
            $secciones[] = new SeccionDocumento('Documento de origen', $this->documento->origen->numero);
        }

        if ($this->documento->motivo) {
            $secciones[] = new SeccionDocumento('Motivo', $this->documento->motivo);
        }

        $soporteMora = $this->soporteMora();

        if ($soporteMora !== null) {
            $secciones[] = new SeccionDocumento('Soporte del cálculo de mora', $soporteMora);
        }

        return $secciones;
    }

    /** Una línea por cada línea con tasa de mora: «Concepto — Tasa: X % · Días: Y · Base: $Z». */
    private function soporteMora(): ?string
    {
        $lineas = $this->documento->lineas->filter(fn ($linea) => $linea->tasa_mora !== null);

        if ($lineas->isEmpty()) {
            return null;
        }

        return $lineas->map(fn ($linea) => sprintf(
            '%s — Tasa: %s %% · Días: %d · Base: $%s',
            $linea->concepto,
            rtrim(rtrim((string) $linea->tasa_mora, '0'), '.'),
            $linea->dias_mora,
            $linea->base_mora->aDecimalString(),
        ))->implode("\n");
    }

    public function pie(): ?string
    {
        return 'Documento interno de facturación. No corresponde a una factura electrónica de venta ante la DIAN.';
    }

    public function marcaAgua(): ?string
    {
        return null;
    }

    public function camposEmpresa(): CamposEmpresaDocumento
    {
        return CamposEmpresaDocumento::todos();
    }
}
