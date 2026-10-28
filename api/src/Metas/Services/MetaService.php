<?php

namespace App\Metas\Services;

use App\Auditoria\Enums\AccionAuditoria;
use App\Auditoria\Services\EmisorAuditoria;
use App\Core\Dinero\Dinero;
use App\Metas\Constants\MetasConstants;
use App\Metas\Data\PeriodoMeta;
use App\Metas\Enums\TipoMeta;
use App\Metas\Models\Meta;
use App\Metas\Repositories\AlertaMetaRepository;
use App\Metas\Repositories\CierreMetaRepository;
use App\Metas\Repositories\MetaAsesorRepository;
use App\Metas\Repositories\MetaRepository;
use App\Usuarios\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MetaService
{
    public function __construct(
        protected MetaRepository $metas,
        protected MetaAsesorRepository $asesores,
        protected CierreMetaRepository $cierres,
        protected AlertaMetaRepository $alertas,
        protected CumplimientoMetasService $cumplimiento,
        protected ConfiguracionMetasService $configuracion,
        protected EmisorAuditoria $auditoria,
    ) {}

    /**
     * Metas del período con su cumplimiento. Quien tiene `metas.listar` ve el detalle de todos los asesores; un asesor
     * (solo con la meta consolidada activa) ve la meta de empresa y únicamente su propia fila.
     */
    public function listar(Usuario $usuario, PeriodoMeta $periodo, CarbonImmutable $hoy): array
    {
        $verTodo = $usuario->tienePermiso(MetasConstants::MODULO, 'listar');
        $empresaId = (int) $usuario->empresa_id;
        $parametros = $this->configuracion->parametros($empresaId);

        $resumen = $this->cumplimiento->resumen($empresaId, $this->metas->delPeriodo($empresaId, $periodo), $hoy, $parametros);

        return [
            'periodo' => $periodo->codigo(),
            'porcentaje_de_dias' => number_format($periodo->centesimasDeDiasTranscurridos($hoy) / 100, 2, '.', ''),
            'ver_detalle' => $verTodo,
            'metas' => array_map(fn (array $item) => [
                'id' => $item['meta']->id,
                'tipo' => $item['meta']->tipo,
                'valor_objetivo' => $item['meta']->valor_objetivo,
                'cerrado' => $item['cerrado'],
                'empresa' => $item['empresa']->aArray(),
                'asesores' => collect($item['asesores'])
                    ->filter(fn (array $asesor) => $verTodo || $asesor['usuario_id'] === $usuario->id)
                    ->map(fn (array $asesor) => [
                        'usuario_id' => $asesor['usuario_id'],
                        'nombre' => $asesor['nombre'],
                        'cumplimiento' => $asesor['cumplimiento']->aArray(),
                    ])
                    ->values()
                    ->all(),
            ], $resumen),
        ];
    }

    public function crear(Usuario $usuario, PeriodoMeta $periodo, TipoMeta $tipo, Dinero $valorObjetivo): Meta
    {
        return DB::transaction(function () use ($usuario, $periodo, $tipo, $valorObjetivo) {
            $meta = $this->metas->crear([
                'periodo' => $periodo->codigo(),
                'tipo' => $tipo->value,
                'valor_objetivo' => $valorObjetivo,
                'creado_por' => $usuario->id,
            ]);

            $this->auditoria->registrarCreacion(
                MetasConstants::MODULO,
                "Meta de {$tipo->value} del período {$periodo->codigo()} creada por {$valorObjetivo->aDecimalString()}",
                $meta,
            );

            return $meta;
        });
    }

    public function editar(Meta $meta, Dinero $valorObjetivo): Meta
    {
        return DB::transaction(function () use ($meta, $valorObjetivo) {
            $anteriores = $this->auditoria->instantanea($meta);
            $meta = $this->metas->actualizar($meta, ['valor_objetivo' => $valorObjetivo]);

            $this->auditoria->registrarModificacion(
                MetasConstants::MODULO,
                "Meta de {$meta->tipo->value} del período {$meta->periodo} modificada",
                $meta,
                $anteriores,
            );

            return $meta;
        });
    }

    /**
     * Reemplaza la distribución por asesor. Con `distribucion_obligatoria_completa` la suma debe ser exacta;
     * si no, puede ser menor o igual a la meta. Una suma mayor siempre responde 422.
     *
     * @param  list<array{usuario_id: int, valor_objetivo: Dinero}>  $filas
     */
    public function distribuir(Usuario $usuario, Meta $meta, array $filas): Meta
    {
        $parametros = $this->configuracion->parametros((int) $usuario->empresa_id);
        $suma = Dinero::sumarVarios(...array_map(fn (array $fila) => $fila['valor_objetivo'], $filas));

        if ($suma->mayorQue($meta->valor_objetivo)) {
            throw ValidationException::withMessages(['asesores' => __('validation.custom.metas.distribucion_supera', [
                'suma' => $suma->aDecimalString(),
                'meta' => $meta->valor_objetivo->aDecimalString(),
            ])]);
        }

        if ($parametros->distribucionObligatoriaCompleta && ! $suma->igualA($meta->valor_objetivo)) {
            throw ValidationException::withMessages(['asesores' => __('validation.custom.metas.distribucion_incompleta', [
                'meta' => $meta->valor_objetivo->aDecimalString(),
            ])]);
        }

        return DB::transaction(function () use ($meta, $filas) {
            $meta->load('asesores');
            $anteriores = $this->distribucionComoTexto($meta->asesores->mapWithKeys(fn ($fila) => [$fila->usuario_id => $fila->valor_objetivo])->all());
            $pares = collect($filas)->mapWithKeys(fn (array $fila) => [$fila['usuario_id'] => $fila['valor_objetivo']])->all();

            $this->asesores->sincronizar($meta, $pares);

            $nuevos = $this->distribucionComoTexto($pares);

            if ($nuevos !== $anteriores) {
                $this->auditoria->registrar(
                    AccionAuditoria::Modificar,
                    MetasConstants::MODULO,
                    "Distribución de la meta de {$meta->tipo->value} del período {$meta->periodo} modificada",
                    'meta',
                    $meta->id,
                    ['asesores' => $anteriores],
                    ['asesores' => $nuevos],
                );
            }

            return $meta->fresh(['asesores']);
        });
    }

    /**
     * Solo una meta sin cierres: un cierre es el resultado oficial del período. Se bloquea la fila primero, igual que
     * el cierre y el recálculo, para que un cierre en curso no se cruce con el borrado.
     */
    public function eliminar(Meta $meta): void
    {
        DB::transaction(function () use ($meta) {
            $meta = $this->metas->bloquear($meta);

            if ($this->cierres->tieneCierres((int) $meta->empresa_id, $meta->id)) {
                throw ValidationException::withMessages(['meta' => __('validation.custom.metas.tiene_cierres')]);
            }

            $this->asesores->eliminarDeMeta($meta->id);
            $this->alertas->eliminarDeMeta($meta->id);
            $this->metas->eliminar($meta);

            $this->auditoria->registrarEliminacion(
                MetasConstants::MODULO,
                "Meta de {$meta->tipo->value} del período {$meta->periodo} eliminada",
                $meta,
            );
        });
    }

    /** @param array<int, Dinero> $distribucion */
    private function distribucionComoTexto(array $distribucion): array
    {
        return array_map(fn (Dinero $valor) => $valor->aDecimalString(), $distribucion);
    }
}
