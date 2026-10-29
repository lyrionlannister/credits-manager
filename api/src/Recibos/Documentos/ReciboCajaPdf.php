<?php

namespace App\Recibos\Documentos;

use App\Core\Dinero\Dinero;
use App\Facturacion\Models\CobroFacturacion;
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
use App\Pagos\Models\Pago;
use App\Recibos\Enums\EstadoRecibo;
use App\Recibos\Enums\OrigenReciboCaja;
use App\Recibos\Models\ReciboCaja;
use Illuminate\Support\Str;

/**
 * Recibo de caja de un pago de crédito o de un cobro de factura (`$recibo->origen_tipo` dice cuál). Espera el
 * recibo con `pago.cliente.tercero`/`pago.detalles.cuota`/`pago.registrador` o
 * `cobroFacturacion.documento.cliente.tercero`/`cobroFacturacion.registrador` ya cargados según corresponda,
 * además de `comprobante.tipoComprobante`, `comprobante.lineas.cuenta` y `anulador`.
 */
final class ReciboCajaPdf implements DocumentoPdf
{
    public function __construct(private readonly ReciboCaja $recibo) {}

    public function titulo(): string
    {
        return $this->anulado() ? 'Recibo de caja ANULADO' : 'Recibo de caja';
    }

    public function consecutivo(): ?string
    {
        return $this->recibo->numero;
    }

    public function nombreArchivo(): string
    {
        return 'recibo-de-caja-'.Str::slug($this->recibo->numero);
    }

    public function orientacion(): OrientacionDocumento
    {
        return OrientacionDocumento::Vertical;
    }

    public function destinatario(): DestinatarioDocumento
    {
        $tercero = $this->esPago() ? $this->pago()->cliente->tercero : $this->cobro()->documento->cliente->tercero;

        return DestinatarioDocumento::desdeTercero($tercero);
    }

    public function fechas(): array
    {
        return [
            new FechaDocumento($this->esPago() ? 'Fecha de pago' : 'Fecha de cobro', $this->esPago() ? $this->pago()->fecha_pago : $this->cobro()->fecha, true),
            new FechaDocumento('Expedición', $this->recibo->creado_en, true),
        ];
    }

    public function tituloTabla(): ?string
    {
        return null;
    }

    public function columnas(): array
    {
        return [
            new ColumnaDocumento('item', 'Ítem', TipoColumnaDocumento::Entero, 8),
            new ColumnaDocumento('descripcion', 'Descripción', TipoColumnaDocumento::Texto, 62),
            new ColumnaDocumento('valor', 'Vr. Aplicado', TipoColumnaDocumento::Monetario, 30),
        ];
    }

    public function filas(): iterable
    {
        if (! $this->esPago()) {
            yield ['item' => 1, 'descripcion' => "Cobro de la factura {$this->cobro()->documento->numero}", 'valor' => $this->cobro()->valor];

            return;
        }

        $pago = $this->pago();

        // La liquidación anticipada cierra las cuotas sin detalle por cuota: se imprime el pago completo en una línea.
        if ($pago->detalles->isEmpty()) {
            yield ['item' => 1, 'descripcion' => "Pago del crédito #{$pago->credito_id}", 'valor' => $pago->valor_pago];

            return;
        }

        foreach ($pago->detalles->values() as $indice => $detalle) {
            $cuota = $detalle->cuota;

            yield [
                'item' => $indice + 1,
                'descripcion' => "Cuota {$cuota->numero_cuota} del crédito #{$pago->credito_id}",
                'valor' => $detalle->valor_aplicado,
            ];
        }
    }

    public function tablasAdicionales(): array
    {
        return [];
    }

    public function totales(): array
    {
        return [new TotalDocumento('Total recibido', $this->valorTotal())];
    }

    private function anulado(): bool
    {
        return $this->recibo->estado === EstadoRecibo::Anulado;
    }

    public function secciones(): array
    {
        $secciones = [
            new SeccionDocumento('Valor en Letras', NumeroALetras::pesos($this->valorTotal())),
            new SeccionDocumento('Forma de pago', $this->esPago() ? ucfirst($this->pago()->forma_pago->value) : $this->cobro()->medio->etiqueta()),
        ];

        $cuentaCajaBanco = $this->cuentaDeCajaOBanco();

        if ($cuentaCajaBanco) {
            $secciones[] = new SeccionDocumento('Cuenta de caja o banco', $cuentaCajaBanco);
        }

        $comprobante = $this->recibo->comprobante;

        if ($comprobante) {
            $secciones[] = new SeccionDocumento('Comprobante contable', "{$comprobante->tipoComprobante->codigo} {$comprobante->consecutivo}");
        }

        $registrador = $this->esPago() ? $this->pago()->registrador : $this->cobro()->registrador;

        if ($registrador) {
            $secciones[] = new SeccionDocumento('Recibido por', $registrador->nombre);
        }

        $observacion = $this->esPago() ? $this->pago()->observacion : $this->cobro()->observacion;

        if ($observacion) {
            $secciones[] = new SeccionDocumento('Observaciones', $observacion);
        }

        if ($this->anulado()) {
            $anuladoPor = $this->recibo->anulador?->nombre ?? 'Sin usuario registrado';
            $secciones[] = new SeccionDocumento(
                'Anulación',
                "Anulado el {$this->recibo->anulado_en->timezone('America/Bogota')->format('d/m/Y H:i')} por {$anuladoPor}.\nMotivo: {$this->recibo->motivo_anulacion}\nEl valor de este recibo no tiene efecto sobre el documento de origen.",
            );
        }

        return $secciones;
    }

    public function pie(): ?string
    {
        return 'Conserve este recibo como soporte de su pago. No corresponde a una factura electrónica de venta.';
    }

    public function marcaAgua(): ?string
    {
        return $this->anulado() ? 'ANULADO' : null;
    }

    public function camposEmpresa(): CamposEmpresaDocumento
    {
        return CamposEmpresaDocumento::todos();
    }

    private function esPago(): bool
    {
        return $this->recibo->origen_tipo === OrigenReciboCaja::Pago;
    }

    private function pago(): Pago
    {
        return $this->recibo->pago;
    }

    private function cobro(): CobroFacturacion
    {
        return $this->recibo->cobroFacturacion;
    }

    private function valorTotal(): Dinero
    {
        return $this->esPago() ? $this->pago()->valor_pago : $this->cobro()->valor;
    }

    /** La cuenta que se debitó en el asiento del recaudo: donde entró el dinero. */
    private function cuentaDeCajaOBanco(): ?string
    {
        $linea = $this->recibo->comprobante?->lineas->first(fn ($asiento) => $asiento->debito->esPositivo());

        return $linea?->cuenta ? "{$linea->cuenta->codigo} {$linea->cuenta->nombre}" : null;
    }
}
