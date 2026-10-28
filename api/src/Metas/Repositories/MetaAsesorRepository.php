<?php

namespace App\Metas\Repositories;

use App\Core\Base\BaseRepository;
use App\Core\Dinero\Dinero;
use App\Metas\Models\Meta;
use App\Metas\Models\MetaAsesor;

class MetaAsesorRepository extends BaseRepository
{
    public function __construct(MetaAsesor $metaAsesor)
    {
        parent::__construct($metaAsesor);
    }

    public function eliminarDeMeta(int $metaId): void
    {
        $this->consulta()->where('meta_id', $metaId)->delete();
    }

    /**
     * Deja la distribución de la meta igual a `$pares` (usuario_id => valor). Lo que sale se borra con soft delete;
     * lo que vuelve a entrar se restaura, porque la unicidad (meta, usuario) incluye las filas borradas.
     *
     * @param  array<int, Dinero>  $pares
     */
    public function sincronizar(Meta $meta, array $pares): void
    {
        $existentes = $this->consulta()->withTrashed()->where('meta_id', $meta->id)->get()->keyBy('usuario_id');

        foreach ($existentes as $usuarioId => $fila) {
            if (! array_key_exists($usuarioId, $pares) && ! $fila->trashed()) {
                $fila->delete();
            }
        }

        foreach ($pares as $usuarioId => $valor) {
            $fila = $existentes->get($usuarioId) ?? $this->modelo->newInstance([
                'empresa_id' => $meta->empresa_id,
                'meta_id' => $meta->id,
                'usuario_id' => $usuarioId,
            ]);

            if ($fila->trashed()) {
                $fila->restore();
            }

            $fila->fill(['valor_objetivo' => $valor])->save();
        }
    }
}
