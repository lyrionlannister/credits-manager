<?php

namespace App\Contabilidad\Documentos;

use App\Impresion\Contracts\DocumentoPdf;
use App\Impresion\Data\CamposEmpresaDocumento;
use App\Impresion\Data\ColumnaDocumento;
use App\Impresion\Data\DestinatarioDocumento;
use App\Impresion\Data\FechaDocumento;
use App\Impresion\Data\SeccionDocumento;
use App\Impresion\Data\TablaDocumento;
use App\Impresion\Data\TotalDocumento;
use App\Impresion\Enums\OrientacionDocumento;

/**
 * Informe contable en PDF (balance de prueba, libros, estados financieros, auxiliares). Recibe ya calculados los datos
 * de su servicio: el documento solo los acomoda en la plantilla común, sin consultar nada.
 */
final class InformeContablePdf implements DocumentoPdf
{
    /**
     * @param  list<FechaDocumento>  $fechasInforme
     * @param  list<ColumnaDocumento>  $columnas
     * @param  list<array<string, mixed>>  $filas
     * @param  list<TotalDocumento>  $totales
     * @param  list<TablaDocumento>  $tablas
     * @param  list<SeccionDocumento>  $secciones
     */
    public function __construct(
        private readonly string $titulo,
        private readonly string $nombreArchivo,
        private readonly string $alcance,
        private readonly array $fechasInforme,
        private readonly ?string $tituloTabla,
        private readonly array $columnas,
        private readonly array $filas,
        private readonly array $totales = [],
        private readonly array $tablas = [],
        private readonly array $secciones = [],
    ) {}

    public function titulo(): string
    {
        return $this->titulo;
    }

    public function consecutivo(): ?string
    {
        return null;
    }

    public function nombreArchivo(): string
    {
        return $this->nombreArchivo;
    }

    public function orientacion(): OrientacionDocumento
    {
        return OrientacionDocumento::Vertical;
    }

    public function destinatario(): DestinatarioDocumento
    {
        return new DestinatarioDocumento($this->alcance, etiquetaDocumento: 'Alcance');
    }

    public function fechas(): array
    {
        return $this->fechasInforme;
    }

    public function tituloTabla(): ?string
    {
        return $this->tituloTabla;
    }

    public function columnas(): array
    {
        return $this->columnas;
    }

    public function filas(): iterable
    {
        yield from $this->filas;
    }

    public function tablasAdicionales(): array
    {
        return $this->tablas;
    }

    public function totales(): array
    {
        return $this->totales;
    }

    public function secciones(): array
    {
        return $this->secciones;
    }

    public function pie(): ?string
    {
        return 'Cifras calculadas sobre comprobantes contabilizados. Los comprobantes borrador o revisados no se incluyen.';
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
