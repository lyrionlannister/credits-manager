<?php

namespace App\Pagos\Repositories;

use App\Core\Base\BaseRepository;
use App\Pagos\Models\PagoDetalle;

class PagoDetalleRepository extends BaseRepository
{
    public function __construct(PagoDetalle $pagoDetalle)
    {
        parent::__construct($pagoDetalle);
    }

    /**
     * Centavos aplicados a cada cuota del crédito por sus pagos no reversados, sin contar `$excluirPagoId`. El
     * `whereHas` sobre `pago` arrastra el `EmpresaScope` de `Pago`, que es lo que acota el detalle a la empresa.
     *
     * @return array<int, int> cuota_id => centavos
     */
    /**
     * Fecha del último pago vigente aplicado a cada cuota del crédito y lo que esos pagos le aplicaron en total.
     *
     * @return array<int, array{fecha: string, total: int}> cuota_id => fecha `Y-m-d` y centavos
     */
    public function ultimoPagoVigentePorCuota(int $creditoId): array
    {
        return $this->consulta()
            ->join('pagos', 'pagos.id', '=', 'pago_detalle.pago_id')
            ->whereHas('pago', fn ($pago) => $pago->where('credito_id', $creditoId)->whereDoesntHave('reversa'))
            ->whereNull('pagos.borrado_en')
            ->groupBy('pago_detalle.cuota_id')
            ->selectRaw('pago_detalle.cuota_id, MAX(pagos.fecha_pago) as fecha, SUM(pago_detalle.valor_aplicado) as total')
            ->get()
            ->mapWithKeys(fn ($fila) => [(int) $fila->cuota_id => ['fecha' => substr((string) $fila->fecha, 0, 10), 'total' => (int) $fila->total]])
            ->all();
    }

    public function sumaVigentePorCuota(int $creditoId, int $excluirPagoId): array
    {
        return $this->consulta()
            ->whereHas('pago', fn ($pago) => $pago
                ->where('credito_id', $creditoId)
                ->where('id', '!=', $excluirPagoId)
                ->whereDoesntHave('reversa'))
            ->groupBy('cuota_id')
            ->selectRaw('cuota_id, SUM(valor_aplicado) as total')
            ->pluck('total', 'cuota_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}
