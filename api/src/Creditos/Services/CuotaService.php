<?php

namespace App\Creditos\Services;

use App\Auditoria\Constants\AuditoriaConstants;
use App\Auditoria\Enums\AccionAuditoria;
use App\Auditoria\Services\EmisorAuditoria;
use App\Core\Dinero\Dinero;
use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Models\Credito;
use App\Creditos\Repositories\CuotaRepository;
use App\Pagos\Services\AplicadorPagoPlan;

class CuotaService
{
    public function __construct(
        protected CuotaRepository $cuotas,
        protected EmisorAuditoria $auditoria,
    ) {}

    /** Corre también sin sesión (scheduler): el evento lleva el `empresa_id` de cada crédito, nunca el de `Auth`. */
    public function actualizarVencidas(): void
    {
        $marcadas = $this->cuotas->marcarVencidas();

        foreach ($marcadas->groupBy('credito.empresa_id') as $empresaId => $cuotas) {
            $this->auditoria->registrar(
                AccionAuditoria::Modificar,
                AuditoriaConstants::MODULO_CARTERA,
                "Mora: {$cuotas->count()} cuota(s) pasaron a vencida (proceso automático)",
                'cuota',
                anteriores: ['estado' => 'pendiente'],
                nuevos: [
                    'estado' => 'vencida',
                    'cuotas' => $cuotas->count(),
                    'creditos' => $cuotas->pluck('credito_id')->unique()->values()->take(AuditoriaConstants::MAXIMO_IDS_EN_EVENTO)->all(),
                ],
                empresaId: (int) $empresaId,
            );
        }
    }

    public function saldoCredito(int $creditoId): Dinero
    {
        return Dinero::desdeCentavos($this->cuotas->saldoPendiente($creditoId));
    }

    /** Capital que aún se debe tras aplicar los pagos (interés primero, luego capital): el saldo a la fecha del último pago. */
    public function capitalPendiente(Credito $credito): Dinero
    {
        return $this->resumenAlDia($credito->id, $credito->valor_prestamo)['saldo_capital_pendiente'];
    }

    public function estadoCartera(Credito $credito): string
    {
        if ($credito->estado === EstadoCredito::Refinanciado) {
            return 'Refinanciado';
        }

        return $this->cuotas->contarVencidas($credito->id) > 0 ? 'En mora' : 'Al día';
    }

    /**
     * Cuánto se debe en mora: lo que falta de las cuotas vencidas (capital, interés y otros gastos del plan) y los días
     * desde la primera vencida. No incluye interés de mora: la empresa no tiene una tasa de mora definida.
     *
     * @return array{monto_vencido: Dinero, cuotas_vencidas: int, dias: int}
     */
    public function resumenMora(int $creditoId): array
    {
        $fila = $this->cuotas->resumenPorCreditos([$creditoId])[$creditoId] ?? ['saldo_mora' => 0, 'vencidas' => 0, 'primera_fecha_vencida' => null];

        return [
            'monto_vencido' => Dinero::desdeCentavos($fila['saldo_mora']),
            'cuotas_vencidas' => $fila['vencidas'],
            'dias' => $this->diasMoraDesdeFecha($fila['primera_fecha_vencida']),
        ];
    }

    public function diasMora(int $creditoId): int
    {
        return $this->diasMoraDesdeFecha($this->cuotas->primeraFechaVencida($creditoId));
    }

    public function diasMoraDesdeFecha(?string $fecha): int
    {
        if (! $fecha) {
            return 0;
        }

        $dias = (strtotime(now()->toDateString()) - strtotime($fecha)) / 86400;

        return (int) $dias;
    }

    /**
     * Agregados "al día" para la vista de detalle de un crédito: mismo orden de
     * aplicación de pago (interés primero, luego capital) que
     * `PagoService::registrarPago`/`capitalPendienteReal`, más la curva teórica de
     * `AmortizacionCalculator` (a partir de `valor_capital`, fijo por cuota y nunca
     * reescrito por un pago) para el plan de amortización — a diferencia de
     * `saldo_capital`, que sí se recalcula tras cada abono y por eso deja de servir
     * como curva una vez hay pagos parciales.
     */
    public function resumenAlDia(int $creditoId, Dinero $valorPrestamo): array
    {
        $interesPendiente = Dinero::cero();
        $capitalPagado = Dinero::cero();
        $saldoCapitalTeorico = $valorPrestamo;
        $curvaPorCuota = [];

        foreach ($this->cuotas->todasPorCredito($creditoId) as $cuota) {
            $valorInteres = $cuota->valor_interes;
            $valorCapital = $cuota->valor_capital;

            $desglose = AplicadorPagoPlan::desglosePagado($cuota->valor_pagado, $valorInteres, $cuota->valor_otros_gastos);
            $interesPendiente = $interesPendiente->sumar($valorInteres)->restar($desglose['interes']);
            $capitalPagado = $capitalPagado->sumar($desglose['capital']);

            $saldoCapitalTeorico = $saldoCapitalTeorico->restar($valorCapital)->noNegativo();
            $curvaPorCuota[$cuota->numero_cuota] = $saldoCapitalTeorico;
        }

        return [
            'interes_pendiente' => $interesPendiente,
            'saldo_capital_pendiente' => $valorPrestamo->restar($capitalPagado)->noNegativo(),
            'saldo_capital_pagado' => $capitalPagado,
            'saldo_capital_teorico_por_cuota' => $curvaPorCuota,
        ];
    }
}
