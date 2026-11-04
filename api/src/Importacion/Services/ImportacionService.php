<?php

namespace App\Importacion\Services;

use App\Auditoria\Enums\AccionAuditoria;
use App\Auditoria\Services\EmisorAuditoria;
use App\Exportacion\Services\ConvertidorValores;
use App\Importacion\Constants\ImportacionConstants;
use App\Importacion\Definiciones\DefinicionImportacion;
use App\Importacion\Enums\EstadoImportacion;
use App\Importacion\Enums\TipoImportacion;
use App\Importacion\Jobs\EscribirImportacionJob;
use App\Importacion\Jobs\ValidarImportacionJob;
use App\Importacion\Lectura\LectorCsv;
use App\Importacion\Models\Importacion;
use App\Importacion\Repositories\ImportacionErrorRepository;
use App\Importacion\Repositories\ImportacionRepository;
use App\Usuarios\Models\Usuario;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lado de la API: solicita, decide, confirma y cancela. El trabajo pesado va a la cola; aquí solo se valida lo que el
 * request puede saber (permisos por tipo, cabecera del archivo, estados) y se registra en la bitácora.
 */
class ImportacionService
{
    public function __construct(
        protected ImportacionRepository $importaciones,
        protected ImportacionErrorRepository $errores,
        protected ImportacionEjecutorService $ejecutor,
        protected EmisorAuditoria $auditoria,
        protected ConvertidorValores $convertidor,
    ) {}

    /** Solo las cargas de las hojas que el usuario puede importar: sus filas y errores traen datos de ese módulo. */
    public function listar(Usuario $usuario, int $porPagina, int $pagina): LengthAwarePaginator
    {
        return $this->importaciones->paginarDeEmpresa((int) $usuario->empresa_id, $this->tiposPermitidos($usuario), max(1, min(100, $porPagina)), max(1, $pagina));
    }

    /**
     * Toda acción sobre una carga existente (ver, decidir, confirmar, cancelar, errores, reporte) pasa por aquí: exige el
     * permiso de su hoja (`importaciones.importar_<hoja>`), el mismo que pide subirla. La ruta solo exige alguno de ellos,
     * así que sin esto el permiso de una hoja bastaría para confirmar la carga de otra.
     */
    public function mostrar(int $id, Usuario $usuario): Importacion
    {
        $importacion = $this->importaciones->buscarDeEmpresaOFallar($id, (int) $usuario->empresa_id);
        $this->exigirPermisoDeTipo($usuario, $importacion->tipo);

        return $importacion;
    }

    public function solicitar(
        Usuario $usuario,
        TipoImportacion $tipo,
        UploadedFile $archivo,
        bool $crearEmpleadores,
        bool $generarAsiento,
        ?int $tipoComprobanteId = null,
    ): Importacion {
        $this->exigirPermisoDeTipo($usuario, $tipo);

        if ($generarAsiento) {
            if (! $usuario->tienePermiso(...ImportacionConstants::PERMISO_CONTABILIZAR)) {
                abort(403, 'No tienes permiso para realizar esta acción.');
            }

            if (! $this->ejecutor->puedeContabilizar($tipo)) {
                throw ValidationException::withMessages(['generar_asiento' => 'Este tipo de carga no genera asientos: no hay una regla de contabilización activa.']);
            }
        }

        $faltantes = (new LectorCsv($archivo->getRealPath(), $tipo))->encabezadosFaltantes();

        if ($faltantes !== null) {
            throw ValidationException::withMessages([
                'archivo' => 'El archivo no coincide con la hoja '.DefinicionImportacion::para($tipo)['hoja'].'. Faltan las columnas: '.implode(', ', $faltantes).'.',
            ]);
        }

        $ruta = Storage::disk(ImportacionConstants::DISCO)->putFileAs(now()->format('Y/m'), $archivo, Str::uuid().'.csv');

        $importacion = $this->importaciones->crear([
            'empresa_id' => $usuario->empresa_id,
            'usuario_id' => $usuario->id,
            'tipo' => $tipo,
            'estado' => EstadoImportacion::Pendiente,
            'archivo_original' => mb_substr($archivo->getClientOriginalName(), 0, 255),
            'archivo_ruta' => $ruta,
            'preferencia_crear_empleadores' => $crearEmpleadores,
            'preferencia_generar_asiento' => $generarAsiento,
            'tipo_comprobante_id' => $tipoComprobanteId,
        ]);

        $this->auditoria->registrar(
            AccionAuditoria::Importar,
            'importaciones',
            "Importación solicitada: {$importacion->archivo_original} ({$tipo->value})",
            'importacion',
            $importacion->id,
            nuevos: ['tipo' => $tipo->value, 'archivo' => $importacion->archivo_original, 'crear_empleadores' => $crearEmpleadores, 'generar_asiento' => $generarAsiento],
            autor: $usuario,
        );

        ValidarImportacionJob::dispatch($importacion->id, $importacion->empresa_id);

        return $importacion;
    }

    /** Solo se decide lo que aplica: empleadores pendientes y asiento con filas aceptadas en un tipo contabilizable. */
    public function decidir(Importacion $importacion, Usuario $usuario, ?bool $crearEmpleadores, ?bool $generarAsiento): Importacion
    {
        $this->exigirEstado($importacion, [EstadoImportacion::PendienteDecision]);

        $cambios = [];

        if ($crearEmpleadores !== null) {
            if ($importacion->empleadores_pendientes === 0) {
                throw ValidationException::withMessages(['crear_empleadores' => 'No hay empleadores pendientes de decisión.']);
            }

            if ($crearEmpleadores && ! $usuario->tienePermiso(...ImportacionConstants::PERMISO_EMPLEADORES)) {
                abort(403, 'No tienes permiso para realizar esta acción.');
            }

            $cambios['decision_crear_empleadores'] = $crearEmpleadores;
        }

        if ($generarAsiento !== null) {
            if (! $this->aplicaDecisionDeAsiento($importacion)) {
                throw ValidationException::withMessages(['generar_asiento' => 'Esta importación no tiene asientos que generar.']);
            }

            if ($generarAsiento) {
                if (! $usuario->tienePermiso(...ImportacionConstants::PERMISO_CONTABILIZAR)) {
                    abort(403, 'No tienes permiso para realizar esta acción.');
                }
            }

            $cambios['decision_generar_asiento'] = $generarAsiento;
        }

        $importacion = $this->importaciones->actualizar($importacion, $cambios);

        $this->auditoria->registrar(
            AccionAuditoria::Modificar,
            'importaciones',
            "Decisiones de la importación #{$importacion->id}",
            'importacion',
            $importacion->id,
            nuevos: array_map(fn (mixed $valor) => (bool) $valor, $cambios),
            autor: $usuario,
        );

        return $importacion;
    }

    public function confirmar(Importacion $importacion, Usuario $usuario): Importacion
    {
        $this->exigirEstado($importacion, [EstadoImportacion::PendienteDecision]);

        if ($importacion->empleadores_pendientes > 0 && $importacion->decision_crear_empleadores === null) {
            throw ValidationException::withMessages(['crear_empleadores' => 'Decide si se crean los empleadores que no existen.']);
        }

        if ($this->aplicaDecisionDeAsiento($importacion) && $importacion->decision_generar_asiento === null) {
            throw ValidationException::withMessages(['generar_asiento' => 'Decide si se genera el asiento contable.']);
        }

        if (! $this->importaciones->transicionar($importacion, [EstadoImportacion::PendienteDecision], EstadoImportacion::Importando)) {
            throw ValidationException::withMessages(['estado' => 'La importación ya fue confirmada.']);
        }

        $importacion->refresh();

        $this->auditoria->registrar(
            AccionAuditoria::Importar,
            'importaciones',
            "Importación confirmada: {$importacion->archivo_original} ({$importacion->filas_aceptadas} filas a escribir)",
            'importacion',
            $importacion->id,
            nuevos: [
                'filas_aceptadas' => $importacion->filas_aceptadas,
                'filas_rechazadas' => $importacion->filas_rechazadas,
                'decision_crear_empleadores' => $importacion->decision_crear_empleadores,
                'decision_generar_asiento' => $importacion->decision_generar_asiento,
            ],
            autor: $usuario,
        );

        EscribirImportacionJob::dispatch($importacion->id, $importacion->empresa_id);

        return $importacion;
    }

    public function cancelar(Importacion $importacion, Usuario $usuario): Importacion
    {
        $this->exigirEstado($importacion, [EstadoImportacion::Pendiente, EstadoImportacion::PendienteDecision]);

        $this->importaciones->transicionar($importacion, [EstadoImportacion::Pendiente, EstadoImportacion::PendienteDecision], EstadoImportacion::Cancelada, ['completada_en' => now()]);
        $importacion->refresh();

        $this->auditoria->registrar(
            AccionAuditoria::Cancelar,
            'importaciones',
            "Importación cancelada: {$importacion->archivo_original}",
            'importacion',
            $importacion->id,
            autor: $usuario,
        );

        return $importacion;
    }

    /** @return array{puede_contabilizar: bool, requiere_decision_empleadores: bool, requiere_decision_asiento: bool} */
    public function decisionesDeImportacion(Importacion $importacion): array
    {
        return [
            'puede_contabilizar' => $this->ejecutor->puedeContabilizar($importacion->tipo),
            'requiere_decision_empleadores' => $importacion->empleadores_pendientes > 0 && $importacion->decision_crear_empleadores === null,
            'requiere_decision_asiento' => $this->aplicaDecisionDeAsiento($importacion) && $importacion->decision_generar_asiento === null,
        ];
    }

    public function errores(Importacion $importacion, int $porPagina, int $pagina): LengthAwarePaginator
    {
        return $this->errores->paginarDeImportacion($importacion->id, max(1, min(100, $porPagina)), max(1, $pagina));
    }

    /** El reporte de errores se descarga como CSV separado por punto y coma, para abrirlo directo en Excel en Colombia. */
    public function reporte(Importacion $importacion, Usuario $usuario): StreamedResponse
    {
        $this->auditoria->registrar(
            AccionAuditoria::Exportar,
            'importaciones',
            "Descarga del reporte de errores: {$importacion->archivo_original}",
            'importacion',
            $importacion->id,
            autor: $usuario,
        );

        return response()->streamDownload(function () use ($importacion) {
            $salida = fopen('php://output', 'wb');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, ['Fila', 'Columna', 'Tipo', 'Fase', 'Motivo'], ';');

            foreach ($this->errores->cursorDeImportacion($importacion->id) as $error) {
                fputcsv($salida, [
                    $this->convertidor->neutralizar((string) ($error->fila ?? '')),
                    $this->convertidor->neutralizar((string) ($error->columna ?? '')),
                    $error->severidad->value === 'aviso' ? 'Aviso' : 'Error',
                    $error->fase->value === 'escritura' ? 'Escritura' : 'Validación',
                    $this->convertidor->neutralizar($error->motivo),
                ], ';');
            }

            fclose($salida);
        }, 'reporte-importacion-'.$importacion->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Guía descargable: columnas, obligatoriedad, nota de dependencias y la fila de encabezados lista para pegar datos. */
    public function guia(TipoImportacion $tipo, Usuario $usuario): StreamedResponse
    {
        $this->exigirPermisoDeTipo($usuario, $tipo);

        $definicion = DefinicionImportacion::para($tipo);

        return response()->streamDownload(function () use ($definicion) {
            $salida = fopen('php://output', 'wb');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, ['Hoja', $definicion['hoja']], ';');
            fputcsv($salida, ['Nota', $definicion['nota']], ';');
            fputcsv($salida, ['Antes de subir', 'Borra la fila de ejemplo (la fila amarilla) y deja los datos reales desde la fila siguiente al encabezado.'], ';');
            fputcsv($salida, [], ';');
            fputcsv($salida, ['Columna', 'Obligatoria', 'Tipo de dato'], ';');

            foreach ($definicion['columnas'] as $columna) {
                fputcsv($salida, [$columna['encabezado'], $columna['obligatoria'] ? 'Sí' : 'No', $this->etiquetaTipo($columna['tipo'])], ';');
            }

            fputcsv($salida, [], ';');
            fputcsv($salida, array_map(fn (array $columna) => $columna['encabezado'], $definicion['columnas']), ';');
            fclose($salida);
        }, 'guia-'.$tipo->value.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function etiquetaTipo(string $tipo): string
    {
        return match ($tipo) {
            'fecha' => 'Fecha: dd/mm/aaaa, aaaa-mm-dd o número de serie de Excel',
            'monto' => 'Monto: 2.000.000, 2.000.000,50 o 2000000.50',
            'decimal' => 'Número con decimales (punto o coma)',
            'entero' => 'Número entero',
            'si_no' => 'Sí o No',
            default => 'Texto',
        };
    }

    private function aplicaDecisionDeAsiento(Importacion $importacion): bool
    {
        return $this->ejecutor->puedeContabilizar($importacion->tipo)
            && ($importacion->filas_aceptadas + $importacion->filas_pendientes) > 0;
    }

    /** @return list<TipoImportacion> */
    private function tiposPermitidos(Usuario $usuario): array
    {
        return array_values(array_filter(
            TipoImportacion::cases(),
            fn (TipoImportacion $tipo) => $usuario->tienePermiso(...DefinicionImportacion::para($tipo)['permiso']),
        ));
    }

    private function exigirPermisoDeTipo(Usuario $usuario, TipoImportacion $tipo): void
    {
        if (! $usuario->tienePermiso(...DefinicionImportacion::para($tipo)['permiso'])) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }
    }

    /** @param list<EstadoImportacion> $permitidos */
    private function exigirEstado(Importacion $importacion, array $permitidos): void
    {
        if (! in_array($importacion->estado, $permitidos, true)) {
            throw ValidationException::withMessages(['estado' => 'La importación no admite esta acción en su estado actual.']);
        }
    }
}
