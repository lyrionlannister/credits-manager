<?php

namespace App\Creditos\Services;

use App\Auditoria\Constants\AuditoriaConstants;
use App\Auditoria\Enums\AccionAuditoria;
use App\Auditoria\Services\EmisorAuditoria;
use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Models\Credito;
use App\Creditos\Repositories\CreditoRepository;
use App\Creditos\Repositories\CuotaRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lleva las cuotas quincenales de créditos ya desembolsados al 15 y al último día del mes. Solo mueve fechas: ningún
 * valor, capital, interés ni pago cambia. Las cuotas pagadas o con abonos se quedan donde están; las demás siguen en
 * orden desde la última de esas (o desde el desembolso). Corre sin sesión, así que la empresa llega explícita.
 * Es idempotente: una segunda pasada no encuentra cambios.
 */
class AlineacionQuincenalService
{
    public function __construct(
        protected CreditoRepository $creditos,
        protected CuotaRepository $cuotas,
        protected EmisorAuditoria $auditoria,
    ) {}

    /** @return list<array{credito: Credito, cambios: list<array{cuota_id: int, numero_cuota: int, antes: string, despues: string}>}> */
    public function planificar(int $empresaId): array
    {
        $plan = [];

        foreach ($this->creditos->quincenalesDesembolsadosDeEmpresa($empresaId) as $credito) {
            $cambios = $this->cambiosDe($credito, $this->cuotas->todasPorCredito($credito->id));

            if ($cambios !== []) {
                $plan[] = ['credito' => $credito, 'cambios' => $cambios];
            }
        }

        return $plan;
    }

    /** @return int créditos alineados */
    public function aplicar(int $empresaId): int
    {
        $alineados = 0;

        foreach ($this->creditos->quincenalesDesembolsadosDeEmpresa($empresaId) as $credito) {
            $alineados += DB::transaction(fn () => $this->alinear($credito, $empresaId)) ? 1 : 0;
        }

        return $alineados;
    }

    private function alinear(Credito $credito, int $empresaId): bool
    {
        $cuotas = $this->cuotas->todasOrdenadasParaActualizar($credito->id);
        $cambios = $this->cambiosDe($credito, $cuotas);

        if ($cambios === []) {
            return false;
        }

        $hoy = CarbonImmutable::now('America/Bogota')->startOfDay();
        $porId = $cuotas->keyBy('id');

        foreach ($cambios as $cambio) {
            $nueva = CarbonImmutable::parse($cambio['despues']);
            $this->cuotas->actualizar($porId[$cambio['cuota_id']], [
                'fecha_vencimiento' => $cambio['despues'],
                'estado' => $nueva->lt($hoy) ? EstadoCuota::Vencida : EstadoCuota::Pendiente,
            ]);
        }

        $this->auditoria->registrar(
            AccionAuditoria::Modificar,
            AuditoriaConstants::MODULO_CREDITOS,
            "Cuotas quincenales del crédito #{$credito->id} alineadas al 15 y fin de mes (proceso manual)",
            'credito',
            $credito->id,
            anteriores: ['fechas' => array_column($cambios, 'antes', 'numero_cuota')],
            nuevos: ['fechas' => array_column($cambios, 'despues', 'numero_cuota')],
            empresaId: $empresaId,
        );

        return true;
    }

    /** @return list<array{cuota_id: int, numero_cuota: int, antes: string, despues: string}> */
    private function cambiosDe(Credito $credito, Collection $cuotas): array
    {
        $tocadas = $cuotas->filter(fn ($cuota) => $cuota->estado === EstadoCuota::Pagada || ! $cuota->valor_pagado->esCero());
        $ultimaTocada = $tocadas->max('numero_cuota');
        $cursor = $ultimaTocada !== null
            ? $tocadas->firstWhere('numero_cuota', $ultimaTocada)->fecha_vencimiento->toImmutable()
            : null;

        $cambios = [];

        foreach ($cuotas as $cuota) {
            if ($ultimaTocada !== null && $cuota->numero_cuota <= $ultimaTocada) {
                continue;
            }

            // Sin cuotas tocadas, la primera sigue la misma regla de días mínimos con que se generó el plan.
            $cursor = $cursor === null
                ? AmortizacionCalculator::primerVencimientoQuincenal(CarbonImmutable::parse($credito->fecha_desembolso))
                : AmortizacionCalculator::siguienteDiaQuincenal($cursor);
            $antes = $cuota->fecha_vencimiento->toDateString();

            if ($antes !== $cursor->toDateString()) {
                $cambios[] = ['cuota_id' => $cuota->id, 'numero_cuota' => $cuota->numero_cuota, 'antes' => $antes, 'despues' => $cursor->toDateString()];
            }
        }

        return $cambios;
    }
}
