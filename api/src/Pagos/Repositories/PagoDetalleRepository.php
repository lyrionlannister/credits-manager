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
