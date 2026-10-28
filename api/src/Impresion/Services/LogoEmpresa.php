<?php

namespace App\Impresion\Services;

use App\Empresas\Models\Empresa;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** El logo de la empresa incrustado en el PDF: el generador nunca sale a la red ni lee rutas arbitrarias. */
final class LogoEmpresa
{
    /** El SVG queda fuera: dompdf no aplica sus máscaras y filtros y lo dibuja con fondo negro. */
    private const TIPOS_ADMITIDOS = ['image/png', 'image/jpeg', 'image/gif'];

    public function comoDataUri(Empresa $empresa): ?string
    {
        $ruta = $empresa->logo_pdf_path ?: $empresa->logo_path;

        if (! $ruta) {
            return null;
        }

        $ruta = Str::after($ruta, 'storage/');
        /** @var FilesystemAdapter $disco */
        $disco = Storage::disk('public');

        if (! $disco->exists($ruta)) {
            return null;
        }

        $tipo = $disco->mimeType($ruta);

        if (! in_array($tipo, self::TIPOS_ADMITIDOS, true)) {
            return null;
        }

        return 'data:'.$tipo.';base64,'.base64_encode($disco->get($ruta));
    }
}
