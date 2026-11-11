<?php

namespace App\Creditos\Repositories;

use App\Core\Base\BaseRepository;
use App\Creditos\Enums\TipoReprogramacion;
use App\Creditos\Models\ReprogramacionCredito;
use Carbon\CarbonInterface;

class ReprogramacionCreditoRepository extends BaseRepository
{
    public function __construct(ReprogramacionCredito $reprogramacion)
    {
        parent::__construct($reprogramacion);
    }

    public function vigenteDePago(int $pagoId): ?ReprogramacionCredito
    {
        return $this->consulta()->where('pago_id', $pagoId)->first();
    }

    /** Una reprogramación manual posterior al pago rehízo cuotas que ese pago tocó: deshacer el pago ya no cuadra. */
    public function existeReprogramacionDespuesDe(int $creditoId, CarbonInterface $momento): bool
    {
        return $this->consulta()
            ->where('credito_id', $creditoId)
            ->where('tipo', TipoReprogramacion::Reprogramacion)
            ->where('creado_en', '>=', $momento)
            ->exists();
    }
}
