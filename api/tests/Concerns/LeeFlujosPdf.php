<?php

namespace Tests\Concerns;

/**
 * Corta cada flujo del PDF por su `/Length` en vez de buscar `endstream`: los bytes comprimidos cambian con la fecha
 * de generación y pueden terminar en `\r` o contener `\nendstream`, lo que dejaba la página sin texto según el día.
 */
trait LeeFlujosPdf
{
    protected function flujosDescomprimidos(string $pdf): string
    {
        preg_match_all('/\/Length\s+(\d+)[^>]*>>\s*stream\r?\n/', $pdf, $cabeceras, PREG_OFFSET_CAPTURE);

        $flujos = [];
        foreach ($cabeceras[0] as $indice => [$cabecera, $posicion]) {
            $datos = substr($pdf, $posicion + strlen($cabecera), (int) $cabeceras[1][$indice][0]);
            $flujos[] = @gzuncompress($datos) ?: '';
        }

        return implode("\n", $flujos);
    }
}
