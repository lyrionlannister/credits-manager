<?php

namespace App\ObligacionesTributarias\Repositories;

use App\Core\Base\BaseRepository;
use App\ObligacionesTributarias\Enums\EstadoObligacionTributaria;
use App\ObligacionesTributarias\Models\ObligacionTributaria;
use Illuminate\Database\Eloquent\Collection;

class ObligacionTributariaRepository extends BaseRepository
{
    public function __construct(ObligacionTributaria $obligacion)
    {
        parent::__construct($obligacion);
    }

    /** @param array{periodo?: string, fecha_desde?: string, fecha_hasta?: string, tipo_obligacion_id?: int, estado?: string, responsable_id?: int} $filtros */
    public function deEmpresa(int $empresaId, array $filtros = []): Collection
    {
        return $this->consulta()
            ->where('empresa_id', $empresaId)
            ->when($filtros['periodo'] ?? null, fn ($consulta, $periodo) => $consulta->where('periodo', $periodo))
            ->when($filtros['fecha_desde'] ?? null, fn ($consulta, $desde) => $consulta->where('fecha_limite', '>=', $desde))
            ->when($filtros['fecha_hasta'] ?? null, fn ($consulta, $hasta) => $consulta->where('fecha_limite', '<=', $hasta))
            ->when($filtros['tipo_obligacion_id'] ?? null, fn ($consulta, $tipoId) => $consulta->where('tipo_obligacion_id', $tipoId))
            ->when($filtros['estado'] ?? null, fn ($consulta, $estado) => $consulta->where('estado', $estado))
            ->when($filtros['responsable_id'] ?? null, fn ($consulta, $responsableId) => $consulta->where('responsable_id', $responsableId))
            ->with(['tipoObligacion:id,nombre,periodicidad', 'responsable:id,nombre'])
            ->orderBy('fecha_limite')
            ->get();
    }

    public function existenDeTipo(int $tipoId): bool
    {
        return $this->consulta()->where('tipo_obligacion_id', $tipoId)->exists();
    }

    /** Explícito por empresa: el comando diario no tiene sesión, así que el scope no filtra por él. */
    public function pendientesDeEmpresa(int $empresaId): Collection
    {
        return $this->consulta()
            ->where('empresa_id', $empresaId)
            ->where('estado', EstadoObligacionTributaria::Pendiente->value)
            ->with('tipoObligacion:id,dias_alerta_previa')
            ->get();
    }
}
