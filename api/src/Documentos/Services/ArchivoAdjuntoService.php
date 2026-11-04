<?php

namespace App\Documentos\Services;

use App\Auditoria\Constants\AuditoriaConstants;
use App\Auditoria\Services\EmisorAuditoria;
use App\Documentos\Models\ArchivoAdjunto;
use App\Documentos\Repositories\ArchivoAdjuntoRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ArchivoAdjuntoService
{
    /** Defensa en profundidad: ningún llamador de `subir()` debería permitir estas extensiones, pero no se confía solo en eso. */
    private const EXTENSIONES_PROHIBIDAS = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'html', 'htm', 'svg', 'js', 'exe', 'sh'];

    public function __construct(
        protected ArchivoAdjuntoRepository $archivos,
        protected EmisorAuditoria $auditoria,
    ) {}

    public function subir(Model $adjuntable, UploadedFile $archivo): ArchivoAdjunto
    {
        $extension = Str::lower($archivo->getClientOriginalExtension());

        if (in_array($extension, self::EXTENSIONES_PROHIBIDAS, true)) {
            throw ValidationException::withMessages(['archivo' => __('validation.custom.archivo.extension_no_permitida')]);
        }

        $ruta = $archivo->store('documentos', 'public');

        $adjunto = $this->archivos->crear([
            'adjuntable_type' => $adjuntable->getMorphClass(),
            'adjuntable_id' => $adjuntable->getKey(),
            'nombre_original' => $archivo->getClientOriginalName(),
            'ruta' => 'storage/'.$ruta,
            'mime_type' => $archivo->getClientMimeType(),
            'tamano' => $archivo->getSize(),
            'subido_por' => Auth::id(),
        ]);

        $this->auditoria->registrarCreacion(
            AuditoriaConstants::MODULO_DOCUMENTOS,
            "Documento {$adjunto->nombre_original} adjuntado a ".Str::lower(class_basename($adjuntable))." #{$adjuntable->getKey()}",
            $adjunto,
        );

        return $adjunto;
    }

    public function listarPara(Model $adjuntable): Collection
    {
        return $this->archivos->paraAdjuntable($adjuntable);
    }
}
