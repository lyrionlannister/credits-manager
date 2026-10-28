<?php

namespace App\ObligacionesTributarias\Repositories;

use App\Core\Base\BaseRepository;
use App\ObligacionesTributarias\Models\AlertaObligacionTributaria;
use Illuminate\Database\Eloquent\Collection;

class AlertaObligacionTributariaRepository extends BaseRepository
{
    public function __construct(AlertaObligacionTributaria $alerta)
    {
        parent::__construct($alerta);
    }

    /** La unicidad (empresa_id, obligacion_id) hace que repetir la evaluación no duplique nada. */
    public function existeParaObligacion(int $obligacionId): bool
    {
        return $this->consulta()->where('obligacion_id', $obligacionId)->exists();
    }

    /**
     * Inserción directa: el modelo solo permite asignación masiva de `vista_en` (campo del usuario), así que
     * los campos generados por el sistema se insertan crudos, igual que `AlertaMetaRepository::insertarSiNoExisten`.
     */
    public function insertarSiNoExiste(array $datos): void
    {
        $this->consulta()->insertOrIgnore($datos);
    }

    /** Soft delete en lote: la alerta no tiene sentido sin su obligación y el listado la cargaría con `obligacion` nula. */
    public function eliminarDeObligacion(int $obligacionId): void
    {
        $this->consulta()->where('obligacion_id', $obligacionId)->delete();
    }

    public function deEmpresa(int $empresaId): Collection
    {
        return $this->consulta()
            ->where('empresa_id', $empresaId)
            ->with(['obligacion:id,periodo,responsable_id', 'obligacion.tipoObligacion:id,nombre'])
            ->orderByDesc('generada_en')
            ->orderByDesc('id')
            ->get();
    }

    public function marcarVista(AlertaObligacionTributaria $alerta): AlertaObligacionTributaria
    {
        if ($alerta->vista_en === null) {
            $alerta->forceFill(['vista_en' => now()])->save();
        }

        return $alerta;
    }
}
