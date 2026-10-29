<?php

namespace App\Impresion\Contracts;

use App\Impresion\Data\CamposEmpresaDocumento;
use App\Impresion\Data\ColumnaDocumento;
use App\Impresion\Data\DestinatarioDocumento;
use App\Impresion\Data\FechaDocumento;
use App\Impresion\Data\SeccionDocumento;
use App\Impresion\Data\TablaDocumento;
use App\Impresion\Data\TotalDocumento;
use App\Impresion\Enums\OrientacionDocumento;

/**
 * Lo único que implementa un documento para imprimirse en PDF. Solo entrega datos: el marco, el encabezado
 * con la empresa, la caja de nombre y consecutivo, el destinatario y las fechas los pone la plantilla base,
 * igual para todos. Cada documento decide únicamente las columnas de la tabla y lo que hay debajo.
 */
interface DocumentoPdf
{
    /** Nombre del documento en la caja del encabezado, p. ej. "Recibo de caja". */
    public function titulo(): string;

    /** Consecutivo bajo el nombre (sin el prefijo "No."); nulo si el documento no lo tiene. */
    public function consecutivo(): ?string;

    /** Nombre del archivo descargado, sin extensión. */
    public function nombreArchivo(): string;

    /** Vertical para todos los documentos; solo el reporte por empleador es horizontal. */
    public function orientacion(): OrientacionDocumento;

    public function destinatario(): DestinatarioDocumento;

    /** @return list<FechaDocumento> */
    public function fechas(): array;

    /** Título sobre la tabla principal; nulo si el documento no lo necesita (factura, recibo). */
    public function tituloTabla(): ?string;

    /** @return list<ColumnaDocumento> */
    public function columnas(): array;

    /**
     * Cada fila indexada por la clave de columna. Monetarios: `Dinero`; fechas: `CarbonInterface`;
     * el resto, texto o números.
     *
     * @return iterable<array<string, mixed>>
     */
    public function filas(): iterable;

    /**
     * Tablas que van bajo la principal, en orden; vacío si el documento solo tiene una.
     *
     * @return list<TablaDocumento>
     */
    public function tablasAdicionales(): array;

    /** @return list<TotalDocumento> */
    public function totales(): array;

    /** @return list<SeccionDocumento> */
    public function secciones(): array;

    /** Texto legal al final del documento, bajo los totales. */
    public function pie(): ?string;

    /** Texto de la marca de agua diagonal sobre todo el documento (p. ej. "ANULADO"); nulo si no aplica. */
    public function marcaAgua(): ?string;

    /** Qué datos de la empresa van en el encabezado; `CamposEmpresaDocumento::todos()` para el comportamiento por defecto. */
    public function camposEmpresa(): CamposEmpresaDocumento;
}
