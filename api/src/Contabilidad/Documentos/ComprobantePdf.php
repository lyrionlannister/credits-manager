<?php

namespace App\Contabilidad\Documentos;

use App\Contabilidad\Enums\EstadoComprobante;
use App\Contabilidad\Models\Comprobante;
use App\Impresion\Contracts\DocumentoPdf;
use App\Impresion\Data\CamposEmpresaDocumento;
use App\Impresion\Data\ColumnaDocumento;
use App\Impresion\Data\DestinatarioDocumento;
use App\Impresion\Data\FechaDocumento;
use App\Impresion\Data\SeccionDocumento;
use App\Impresion\Data\TotalDocumento;
use App\Impresion\Enums\OrientacionDocumento;
use App\Impresion\Enums\TipoColumnaDocumento;
use Illuminate\Support\Str;

/**
 * Comprobante contable con sus asientos. Espera `tipoComprobante`, `tercero`, `lineas.cuenta`, `creadoPor` y
 * `comprobanteReverso.tipoComprobante` ya cargados; `$original` es el comprobante que este reversa, si es un reverso.
 */
final class ComprobantePdf implements DocumentoPdf
{
    public function __construct(
        private readonly Comprobante $comprobante,
        private readonly ?Comprobante $original = null,
    ) {}

    public function titulo(): string
    {
        return 'Comprobante contable';
    }

    public function consecutivo(): ?string
    {
        return $this->numero($this->comprobante);
    }

    public function nombreArchivo(): string
    {
        return 'comprobante-'.Str::slug($this->numero($this->comprobante));
    }

    public function orientacion(): OrientacionDocumento
    {
        return OrientacionDocumento::Vertical;
    }

    public function destinatario(): DestinatarioDocumento
    {
        $tercero = $this->comprobante->tercero;

        if (! $tercero) {
            return new DestinatarioDocumento('Sin tercero asociado');
        }

        return DestinatarioDocumento::desdeTercero($tercero);
    }

    public function fechas(): array
    {
        $fechas = [new FechaDocumento('Fecha', $this->comprobante->fecha)];

        if ($this->comprobante->contabilizado_en) {
            $fechas[] = new FechaDocumento('Contabilizado', $this->comprobante->contabilizado_en, true);
        }

        if ($this->comprobante->anulado_en) {
            $fechas[] = new FechaDocumento('Anulado', $this->comprobante->anulado_en, true);
        }

        return $fechas;
    }

    public function tituloTabla(): ?string
    {
        return 'Asientos contables';
    }

    public function columnas(): array
    {
        return [
            new ColumnaDocumento('cuenta', 'Cuenta', TipoColumnaDocumento::Texto, 34),
            new ColumnaDocumento('detalle', 'Detalle', TipoColumnaDocumento::Texto, 26),
            new ColumnaDocumento('debito', 'Débito', TipoColumnaDocumento::Monetario, 20),
            new ColumnaDocumento('credito', 'Crédito', TipoColumnaDocumento::Monetario, 20),
        ];
    }

    public function filas(): iterable
    {
        foreach ($this->comprobante->lineas as $linea) {
            yield [
                'cuenta' => $linea->cuenta ? "{$linea->cuenta->codigo} - {$linea->cuenta->nombre}" : '',
                'detalle' => $linea->descripcion,
                // Solo se imprime el lado que tiene valor.
                'debito' => $linea->debito->esPositivo() ? $linea->debito : null,
                'credito' => $linea->credito->esPositivo() ? $linea->credito : null,
            ];
        }
    }

    public function tablasAdicionales(): array
    {
        return [];
    }

    public function totales(): array
    {
        return [
            new TotalDocumento('Total débito', $this->comprobante->total_debito),
            new TotalDocumento('Total crédito', $this->comprobante->total_credito),
        ];
    }

    public function secciones(): array
    {
        $secciones = [
            new SeccionDocumento('Concepto', $this->comprobante->concepto ?: '—'),
            new SeccionDocumento('Estado', ucfirst($this->comprobante->estado->value)),
        ];

        if ($this->comprobante->documento_soporte) {
            $secciones[] = new SeccionDocumento('Documento soporte', $this->comprobante->documento_soporte);
        }

        if ($this->comprobante->modulo_origen && $this->comprobante->modulo_origen !== 'reverso') {
            $secciones[] = new SeccionDocumento('Origen', "Módulo {$this->comprobante->modulo_origen}".($this->comprobante->origen_id ? ", registro #{$this->comprobante->origen_id}" : ''));
        }

        if ($this->original) {
            $secciones[] = new SeccionDocumento('Reverso de', 'Comprobante '.$this->numero($this->original));
        }

        if ($this->comprobante->comprobanteReverso) {
            $secciones[] = new SeccionDocumento('Reversado por', 'Comprobante '.$this->numero($this->comprobante->comprobanteReverso));
        }

        if ($this->comprobante->creadoPor) {
            $secciones[] = new SeccionDocumento('Elaborado por', $this->comprobante->creadoPor->nombre);
        }

        if ($this->comprobante->revisor) {
            $secciones[] = new SeccionDocumento('Revisado por', $this->comprobante->revisor->nombre);
        }

        return $secciones;
    }

    public function pie(): ?string
    {
        return $this->comprobante->estado === EstadoComprobante::Anulado
            ? 'Comprobante anulado: conserva su número y su efecto fue reversado por otro comprobante.'
            : null;
    }

    public function marcaAgua(): ?string
    {
        return null;
    }

    public function camposEmpresa(): CamposEmpresaDocumento
    {
        return CamposEmpresaDocumento::todos();
    }

    private function numero(Comprobante $comprobante): string
    {
        return $comprobante->numero;
    }
}
