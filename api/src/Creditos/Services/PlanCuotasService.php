<?php

namespace App\Creditos\Services;

use App\Core\Dinero\Dinero;
use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Models\Credito;
use App\Creditos\Models\Cuota;
use App\Creditos\Repositories\CreditoRepository;
use App\Creditos\Repositories\CuotaRepository;
use App\Pagos\Services\AplicadorPagoPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Rehace cuotas no pagadas de un crédito desembolsado (abono a capital, reprogramación) y guarda o devuelve la foto
 * del plan anterior. Las cuotas pagadas nunca se tocan. Quien llama ya tiene el crédito bloqueado y dentro de una
 * transacción.
 */
class PlanCuotasService
{
    public function __construct(
        protected CuotaRepository $cuotas,
        protected CreditoRepository $creditos,
    ) {}

    public function fotografiar(Credito $credito): array
    {
        return [
            'credito' => [
                'plazo' => $credito->plazo,
                'tasa_interes' => (string) $credito->tasa_interes,
                'valor_cuota' => $credito->valor_cuota->centavos(),
                'valor_total' => $credito->valor_total->centavos(),
            ],
            'cuotas' => $this->cuotas->todasPorCredito($credito->id)->map(fn (Cuota $cuota) => [
                'id' => $cuota->id,
                'numero_cuota' => $cuota->numero_cuota,
                'fecha_vencimiento' => $cuota->fecha_vencimiento->toDateString(),
                'valor' => $cuota->valor->centavos(),
                'valor_interes' => $cuota->valor_interes->centavos(),
                'valor_otros_gastos' => $cuota->valor_otros_gastos->centavos(),
                'valor_capital' => $cuota->valor_capital->centavos(),
                'saldo_capital' => $cuota->saldo_capital->centavos(),
                'valor_pagado' => $cuota->valor_pagado->centavos(),
                'estado' => $cuota->estado->value,
            ])->values()->all(),
        ];
    }

    /** @return list<int> ids de las cuotas devueltas a su estado anterior */
    public function restaurar(Credito $credito, array $foto): array
    {
        $ids = array_column($foto['cuotas'], 'id');
        $this->cuotas->eliminarDeCreditoExcepto($credito->id, $ids);

        $actuales = $this->cuotas->deCreditoConEliminadas($credito->id, $ids)->keyBy('id');

        foreach ($foto['cuotas'] as $fila) {
            $cuota = $actuales[$fila['id']];

            if ($cuota->trashed()) {
                $this->cuotas->restaurar($cuota);
            }

            $this->cuotas->actualizar($cuota, [
                'numero_cuota' => $fila['numero_cuota'],
                'fecha_vencimiento' => $fila['fecha_vencimiento'],
                'valor' => Dinero::desdeCentavos($fila['valor']),
                'valor_interes' => Dinero::desdeCentavos($fila['valor_interes']),
                'valor_otros_gastos' => Dinero::desdeCentavos($fila['valor_otros_gastos']),
                'valor_capital' => Dinero::desdeCentavos($fila['valor_capital']),
                'saldo_capital' => Dinero::desdeCentavos($fila['saldo_capital']),
                'valor_pagado' => Dinero::desdeCentavos($fila['valor_pagado']),
                'estado' => EstadoCuota::from($fila['estado']),
            ]);
        }

        $this->creditos->actualizar($credito, [
            'plazo' => $foto['credito']['plazo'],
            'tasa_interes' => $foto['credito']['tasa_interes'],
            'valor_cuota' => Dinero::desdeCentavos($foto['credito']['valor_cuota']),
            'valor_total' => Dinero::desdeCentavos($foto['credito']['valor_total']),
        ]);

        return $ids;
    }

    /**
     * Amortiza `$capital` en una cuota por fecha de `$fechas` (sistema francés, misma tasa por periodo). Reutiliza las
     * cuotas sin pagos de `$reemplazables` en orden y deja en `borrado_en` las que sobran; si hacen falta más, las crea
     * a continuación del último número. Con capital cero las cuotas quedan en cero y pagadas.
     *
     * @param  Collection<int, Cuota>  $reemplazables  ya ordenadas y sin pagos
     * @param  list<string>  $fechas  `Y-m-d`, una por cuota nueva
     */
    public function rehacerCuotas(Credito $credito, Collection $reemplazables, Dinero $capital, float $tasa, array $fechas, int $siguienteNumero, ?Dinero $otrosPorCuota = null): array
    {
        $calculo = AmortizacionCalculator::calcular($capital, $tasa, count($fechas), $credito->tipo_pago, $fechas[0]);
        $plan = $calculo['cuotas'];
        $hoy = CarbonImmutable::now('America/Bogota')->startOfDay();
        $sinCapital = ! $capital->esPositivo();
        $reemplazables = $reemplazables->values();

        foreach ($plan as $posicion => $fila) {
            $otros = $sinCapital ? Dinero::cero() : ($otrosPorCuota ?? $reemplazables[$posicion]->valor_otros_gastos ?? Dinero::cero());
            $datos = [
                'fecha_vencimiento' => $fechas[$posicion],
                'valor' => $fila['valor'],
                'valor_interes' => $fila['valor_interes'],
                'valor_capital' => $fila['valor_capital'],
                'valor_otros_gastos' => $otros,
                'saldo_capital' => $capital,
                'valor_pagado' => Dinero::cero(),
                'estado' => match (true) {
                    $sinCapital => EstadoCuota::Pagada,
                    CarbonImmutable::parse($fechas[$posicion])->lt($hoy) => EstadoCuota::Vencida,
                    default => EstadoCuota::Pendiente,
                },
            ];

            if (isset($reemplazables[$posicion])) {
                $this->cuotas->actualizar($reemplazables[$posicion], $datos);
            } else {
                $this->cuotas->crear([...$datos, 'credito_id' => $credito->id, 'numero_cuota' => $siguienteNumero++]);
            }
        }

        $sobrantes = $reemplazables->slice(count($plan));
        foreach ($sobrantes as $cuota) {
            $this->cuotas->eliminar($cuota);
        }

        return $calculo;
    }

    /** Misma derivación que `PagoService::recalcularSaldoCapital`: el saldo corrido según lo pagado de cada cuota. */
    public function recalcularSaldoCapital(Credito $credito): void
    {
        $capitalRestante = $credito->valor_prestamo;

        foreach ($this->cuotas->todasOrdenadasParaActualizar($credito->id) as $cuota) {
            $capitalRestante = $capitalRestante->restar(AplicadorPagoPlan::desglosePagado($cuota->valor_pagado, $cuota->valor_interes, $cuota->valor_otros_gastos)['capital']);
            $this->cuotas->actualizar($cuota, ['saldo_capital' => $capitalRestante->noNegativo()]);
        }
    }

    /** Plazo, cuota y total del crédito a partir de las cuotas vigentes, tras rehacer el plan. */
    public function actualizarTotalesDelCredito(Credito $credito, Dinero $valorCuota, ?float $tasa = null): Credito
    {
        $vigentes = $this->cuotas->todasPorCredito($credito->id);

        return $this->creditos->actualizar($credito, array_filter([
            'plazo' => $vigentes->count(),
            'valor_cuota' => $valorCuota,
            'valor_total' => Dinero::sumarVarios(...$vigentes->pluck('valor')->all()),
            'tasa_interes' => $tasa,
        ], fn ($valor) => $valor !== null));
    }
}
