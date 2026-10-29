<?php

namespace App\Impresion\Services;

use App\Empresas\Models\Empresa;
use App\Impresion\Constants\ImpresionConstants;
use App\Impresion\Contracts\DocumentoPdf;
use App\Impresion\Enums\OrientacionDocumento;
use Dompdf\Dompdf;
use Dompdf\Options;

/** Único lugar que conoce la librería de PDF: los documentos solo entregan datos y la plantilla base los dibuja. */
final class GeneradorPdf
{
    public function __construct(
        private readonly LogoEmpresa $logos,
        private readonly FormateadorDocumento $formateador,
    ) {}

    /** @return string bytes del PDF */
    public function generar(DocumentoPdf $documento, Empresa $empresa): string
    {
        $empresa->loadMissing(['municipio:id,estado_id,nombre', 'municipio.estado:id,pais_id,nombre', 'municipio.estado.pais:id,nombre']);

        $filas = [];

        foreach ($documento->filas() as $fila) {
            $filas[] = $fila;
        }

        $campos = $documento->camposEmpresa();

        $html = view('impresion.documento', [
            'empresa' => $empresa,
            'campos' => $campos,
            'ubicacion' => $campos->ubicacion ? $this->ubicacion($empresa) : null,
            'logo' => $campos->logo ? $this->logos->comoDataUri($empresa) : null,
            'documento' => $documento,
            'columnas' => $documento->columnas(),
            'filas' => $filas,
            'tablasAdicionales' => $documento->tablasAdicionales(),
            'horizontal' => $documento->orientacion() === OrientacionDocumento::Horizontal,
            'formateador' => $this->formateador,
        ])->render();

        $dompdf = new Dompdf($this->opciones());
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper(ImpresionConstants::PAPEL, $documento->orientacion()->value);
        $dompdf->render();

        $this->numerarPaginas($dompdf);

        return $dompdf->output();
    }

    private function opciones(): Options
    {
        $cache = storage_path('app/'.ImpresionConstants::CARPETA_CACHE);

        if (! is_dir($cache)) {
            mkdir($cache, 0775, true);
        }

        $opciones = new Options;
        $opciones->setDefaultFont(ImpresionConstants::FUENTE);
        $opciones->setIsRemoteEnabled(false);
        $opciones->setIsPhpEnabled(false);
        $opciones->setChroot([resource_path('views/impresion')]);
        $opciones->setFontCache($cache);
        $opciones->setTempDir($cache);

        return $opciones;
    }

    private function ubicacion(Empresa $empresa): ?string
    {
        $municipio = $empresa->municipio;

        if (! $municipio) {
            return null;
        }

        return collect([$municipio->nombre, $municipio->estado?->pais?->nombre])->filter()->implode(' - ');
    }

    private function numerarPaginas(Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();
        $fuente = $dompdf->getFontMetrics()->getFont(ImpresionConstants::FUENTE);

        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 30, 'Página {PAGE_NUM} de {PAGE_COUNT}', $fuente, 7, [0.4, 0.4, 0.4]);
    }
}
