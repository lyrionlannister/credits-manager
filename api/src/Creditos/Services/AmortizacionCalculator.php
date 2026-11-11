<?php

namespace App\Creditos\Services;

use App\Core\Dinero\Dinero;
use App\Creditos\Enums\TipoPago;
use Carbon\CarbonImmutable;

/**
 * Sistema francés (cuota fija, interés compuesto sobre saldo insoluto). Cálculo puro,
 * sin acceso a base de datos — misma fórmula y mismo orden de redondeo que el legado
 * (sistema-creditos/includes/functions.php::generarCuotas). No modificar sin verificar
 * contra el legado: la última cuota absorbe el redondeo acumulado.
 *
 * El float solo vive en el cálculo del factor de la cuota nivelada y en la
 * multiplicación saldo × tasa (`$i`) — es la tasa de interés, no dinero. En el momento
 * en que un número se convierte en "la cuota", "el interés del período" o "el capital
 * del período" se redondea una única vez a centavo vía `Dinero`, nunca se acumula
 * error de flotante en sumas/restas posteriores (esas son aritmética entera exacta).
 *
 * Diferencias deliberadas con el legado: el mes se suma sin desbordar (un desembolso del 31 de enero vence el 28 de
 * febrero, no el 3 de marzo) y, si se da la fecha del primer vencimiento, el interés de la primera cuota se prorratea
 * por los días reales desde el desembolso; el capital de cada cuota no cambia.
 */
class AmortizacionCalculator
{
    public static function calcular(
        Dinero $valorPrestamo,
        float $tasaInteres,
        int $plazo,
        TipoPago $tipoPago,
        string $fechaDesembolso,
        ?string $fechaPrimerVencimiento = null,
    ): array {
        $i = $tasaInteres / 100;

        if ($i > 0) {
            $factorCuota = $i / (1 - (1 + $i) ** -$plazo);
            $valorCuota = $valorPrestamo->multiplicarPorTasa($factorCuota);
        } else {
            $valorCuota = $valorPrestamo->dividirEntre($plazo);
        }

        $desembolso = CarbonImmutable::parse($fechaDesembolso)->startOfDay();
        $primerVencimiento = $fechaPrimerVencimiento !== null ? CarbonImmutable::parse($fechaPrimerVencimiento)->startOfDay() : null;

        // El vencimiento de siempre no se prorratea: un mes de 31 días no debe cobrar 31/30 del interés.
        if ($primerVencimiento?->equalTo(self::sumarPeriodos($desembolso, 1, $tipoPago))) {
            $primerVencimiento = null;
        }
        $saldo = $valorPrestamo;
        $valorTotal = Dinero::cero();
        $cuotas = [];

        for ($n = 1; $n <= $plazo; $n++) {
            $fechaCuota = $primerVencimiento !== null
                ? self::sumarPeriodos($primerVencimiento, $n - 1, $tipoPago)
                : self::sumarPeriodos($desembolso, $n, $tipoPago);

            $interesPeriodo = $saldo->multiplicarPorTasa($i);
            $interesCobrado = $interesPeriodo;

            if ($n === 1 && $primerVencimiento !== null) {
                $dias = (int) $desembolso->diffInDays($primerVencimiento);
                $interesCobrado = $saldo->multiplicarPorTasa($i * $dias / $tipoPago->diasPorPeriodo());
            }

            if ($n === $plazo) {
                $capitalPeriodo = $saldo;
                $valorEstaCuota = $capitalPeriodo->sumar($interesCobrado);
            } else {
                $capitalPeriodo = $valorCuota->restar($interesPeriodo);
                $valorEstaCuota = $capitalPeriodo->sumar($interesCobrado);
            }

            $interesPeriodo = $interesCobrado;

            $saldo = $saldo->restar($capitalPeriodo);
            $valorTotal = $valorTotal->sumar($valorEstaCuota);

            $cuotas[] = [
                'numero_cuota' => $n,
                'fecha_vencimiento' => $fechaCuota->format('Y-m-d'),
                'valor' => $valorEstaCuota,
                'valor_interes' => $interesPeriodo,
                'valor_capital' => $capitalPeriodo,
                'saldo_capital' => $saldo->noNegativo(),
            ];
        }

        return [
            'valor_cuota' => $valorCuota,
            'valor_total' => $valorTotal,
            'cuotas' => $cuotas,
        ];
    }

    public static function sumarPeriodos(CarbonImmutable $fecha, int $periodos, TipoPago $tipoPago): CarbonImmutable
    {
        return $tipoPago === TipoPago::Mensual
            ? $fecha->addMonthsNoOverflow($periodos)
            : $fecha->addDays($periodos * $tipoPago->diasPorPeriodo());
    }
}
