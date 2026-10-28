<?php

namespace App\Metas\Repositories;

use App\Core\Base\BaseRepository;
use App\Metas\Constants\MetasConstants;
use App\Metas\Models\AlertaMeta;
use Illuminate\Database\Eloquent\Collection;

class AlertaMetaRepository extends BaseRepository
{
    public function __construct(AlertaMeta $alerta)
    {
        parent::__construct($alerta);
    }

    /**
     * La unicidad (meta, usuario_clave, estado, periodo) hace que repetir la evaluación no duplique nada:
     * la fila existente se conserva con su `vista_en`.
     *
     * @param  list<array<string, mixed>>  $filas
     */
    public function insertarSiNoExisten(array $filas): void
    {
        if ($filas !== []) {
            $this->consulta()->insertOrIgnore($filas);
        }
    }

    /** Soft delete en lote, por el mismo motivo que `AlertaObligacionTributariaRepository::eliminarDeObligacion`. */
    public function eliminarDeMeta(int $metaId): void
    {
        $this->consulta()->where('meta_id', $metaId)->delete();
    }

    /** @param int|null $usuarioId null: todas las alertas de la empresa; si no, las de empresa y las del propio asesor */
    public function visiblesPara(int $empresaId, ?int $usuarioId): Collection
    {
        return $this->consulta()
            ->where('empresa_id', $empresaId)
            ->when($usuarioId !== null, fn ($consulta) => $consulta->where(fn ($grupo) => $grupo
                ->whereNull('usuario_id')
                ->orWhere('usuario_id', $usuarioId)))
            ->with(['meta:id,tipo,periodo', 'usuario:id,nombre'])
            ->orderByDesc('generada_en')
            ->orderByDesc('id')
            ->limit(MetasConstants::LIMITE_ALERTAS)
            ->get();
    }
}
