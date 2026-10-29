<?php

namespace Tests\Support;

use App\Core\Dinero\Dinero;
use App\Impresion\Contracts\DocumentoPdf;
use App\Impresion\Data\CamposEmpresaDocumento;
use App\Impresion\Data\ColumnaDocumento;
use App\Impresion\Data\DestinatarioDocumento;
use App\Impresion\Data\FechaDocumento;
use App\Impresion\Data\SeccionDocumento;
use App\Impresion\Data\TablaDocumento;
use App\Impresion\Data\TotalDocumento;
use App\Impresion\Enums\OrientacionDocumento;
use App\Impresion\Enums\TipoColumnaDocumento;
use Carbon\CarbonImmutable;

/** Documento mínimo con los datos de la plantilla de DDA, para probar el generador sin depender de ningún módulo. */
final class DocumentoPdfDePrueba implements DocumentoPdf
{
    /**
     * @param  list<array<string, mixed>>|null  $filas
     * @param  list<TablaDocumento>  $tablasAdicionales
     */
    public function __construct(
        private readonly ?array $filas = null,
        private readonly ?string $consecutivo = 'DDA 186',
        private readonly OrientacionDocumento $orientacion = OrientacionDocumento::Vertical,
        private readonly array $tablasAdicionales = [],
        private readonly ?string $tituloTabla = null,
        private readonly ?string $marcaAgua = null,
    ) {}

    public function titulo(): string
    {
        return 'Documento de prueba';
    }

    public function consecutivo(): ?string
    {
        return $this->consecutivo;
    }

    public function nombreArchivo(): string
    {
        return 'documento-de-prueba';
    }

    public function orientacion(): OrientacionDocumento
    {
        return $this->orientacion;
    }

    public function tituloTabla(): ?string
    {
        return $this->tituloTabla;
    }

    public function tablasAdicionales(): array
    {
        return $this->tablasAdicionales;
    }

    public function destinatario(): DestinatarioDocumento
    {
        return new DestinatarioDocumento('CLIENTE DE PRUEBA S.A.S.', '900.111.222-3', '(57) 3001112222', 'CRA 9 #23-30', 'Santa Marta - Colombia');
    }

    public function fechas(): array
    {
        $generacion = CarbonImmutable::parse('2026-07-16 02:19:00', 'UTC');

        return [
            new FechaDocumento('Generación', $generacion, true),
            new FechaDocumento('Expedición', $generacion, true),
            new FechaDocumento('Vencimiento', CarbonImmutable::parse('2026-07-15')),
        ];
    }

    public function columnas(): array
    {
        return [
            new ColumnaDocumento('item', 'Ítem', TipoColumnaDocumento::Entero, 8),
            new ColumnaDocumento('descripcion', 'Descripción', TipoColumnaDocumento::Texto, 52),
            new ColumnaDocumento('cantidad', 'Cantidad', TipoColumnaDocumento::Decimal, 15),
            new ColumnaDocumento('valor', 'Vr. Total', TipoColumnaDocumento::Monetario, 25),
        ];
    }

    public function filas(): iterable
    {
        return $this->filas ?? [
            ['item' => 1, 'descripcion' => 'INTERES.AMORT.ADM', 'cantidad' => 1, 'valor' => Dinero::desdePesos(1222380)],
        ];
    }

    public function totales(): array
    {
        return [
            new TotalDocumento('Total Bruto', Dinero::desdePesos(1222380)),
            new TotalDocumento('Total a Pagar', Dinero::desdePesos(1222380)),
        ];
    }

    public function secciones(): array
    {
        return [
            new SeccionDocumento('Valor en Letras', 'Un millón doscientos veintidós mil trescientos ochenta pesos m/cte'),
            new SeccionDocumento('Forma de pago', 'Contado'),
            new SeccionDocumento('Observaciones', 'SE REALIZA POR VALOR DE 3 MESES DE INTERESES'),
        ];
    }

    public function pie(): ?string
    {
        return 'Documento interno sin validez fiscal. No corresponde a una factura electrónica de venta.';
    }

    public function marcaAgua(): ?string
    {
        return $this->marcaAgua;
    }

    public function camposEmpresa(): CamposEmpresaDocumento
    {
        return CamposEmpresaDocumento::todos();
    }
}
