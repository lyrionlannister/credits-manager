<?php

namespace App\Causacion\Repositories;

use App\Causacion\Models\ValorUvt;
use App\Core\Base\BaseRepository;
use App\Gastos\Models\Gasto;
use Illuminate\Database\Eloquent\Collection;

class ValorUvtRepository extends BaseRepository
{
    public function __construct(ValorUvt $valorUvt)
    {
        parent::__construct($valorUvt);
    }

    public function todas(): Collection
    {
        return $this->consulta()->orderByDesc('fecha_desde')->get();
    }

    public function vigenteParaFecha(string $fecha): ?ValorUvt
    {
        return $this->consulta()
            ->where('fecha_desde', '<=', $fecha)
            ->where(fn ($query) => $query->whereNull('fecha_hasta')->orWhere('fecha_hasta', '>=', $fecha))
            ->first();
    }

    /** La vigencia sin fecha de cierre, si existe (la que hay que cerrar al crear una nueva). */
    public function abierta(): ?ValorUvt
    {
        return $this->consulta()->whereNull('fecha_hasta')->first();
    }

    public function existeEnRangoCerrado(string $fechaDesde): bool
    {
        return $this->consulta()
            ->whereNotNull('fecha_hasta')
            ->where('fecha_desde', '<=', $fechaDesde)
            ->where('fecha_hasta', '>=', $fechaDesde)
            ->exists();
    }

    /** Si ya hay un gasto causado con fecha dentro del rango de vigencia de este valor, queda protegido. */
    public function tieneUso(ValorUvt $valorUvt): bool
    {
        return Gasto::query()
            ->where('fecha', '>=', $valorUvt->fecha_desde->toDateString())
            ->when($valorUvt->fecha_hasta, fn ($query) => $query->where('fecha', '<=', $valorUvt->fecha_hasta->toDateString()))
            ->exists();
    }
}
