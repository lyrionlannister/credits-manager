<?php

namespace App\Creditos\Services;

use App\Core\Dinero\Dinero;
use App\Creditos\Enums\TipoPago;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

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
 *
 * Quincenal: las cuotas vencen en días fijos, el 15 y el último día de cada mes (decisión de DDA), no cada 15 días.
 * El primer vencimiento es el siguiente de esos días después del desembolso, así que el primer periodo casi nunca dura
 * 15 días exactos: su interés siempre se prorratea por los días reales.
 *
 * `$prorratearPrimera = false` cobra el periodo completo en la primera cuota: lo usa quien rehace cuotas de un plan
 * en curso, donde la primera fecha no es la de un desembolso.
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
        bool $prorratearPrimera = true,
    ): array {
        $i = $tasaInteres / 100;

        if ($i > 0) {
            $factorCuota = $i / (1 - (1 + $i) ** -$plazo);
            $valorCuota = $valorPrestamo->multiplicarPorTasa($factorCuota);
        } else {
            $valorCuota = $valorPrestamo->dividirEntre($plazo);
        }

        $desembolso = CarbonImmutable::parse($fechaDesembolso)->startOfDay();
        $primerVencimiento = $fechaPrimerVencimiento !== null
            ? CarbonImmutable::parse($fechaPrimerVencimiento)->startOfDay()
            : self::sumarPeriodos($desembolso, 1, $tipoPago);

        if ($tipoPago === TipoPago::Quincenal && ! self::esDiaQuincenal($primerVencimiento)) {
            throw new InvalidArgumentException('El primer vencimiento quincenal debe caer el 15 o el último día del mes.');
        }

        // Mensual: el vencimiento de siempre no se prorratea (un mes de 31 días no debe cobrar 31/30 del interés).
        $prorratear = $prorratearPrimera && ($tipoPago === TipoPago::Quincenal || ! $primerVencimiento->equalTo(self::sumarPeriodos($desembolso, 1, $tipoPago)));
        $saldo = $valorPrestamo;
        $valorTotal = Dinero::cero();
        $cuotas = [];

        for ($n = 1; $n <= $plazo; $n++) {
            $fechaCuota = $tipoPago === TipoPago::Mensual && $fechaPrimerVencimiento === null
                ? self::sumarPeriodos($desembolso, $n, $tipoPago)
                : self::sumarPeriodos($primerVencimiento, $n - 1, $tipoPago);

            $interesPeriodo = $saldo->multiplicarPorTasa($i);
            $interesCobrado = $interesPeriodo;

            if ($n === 1 && $prorratear) {
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

    /** Quincenal: cada periodo salta al siguiente 15 o fin de mes, así que desde cualquier fecha cae en un día fijo. */
    public static function sumarPeriodos(CarbonImmutable $fecha, int $periodos, TipoPago $tipoPago): CarbonImmutable
    {
        if ($tipoPago === TipoPago::Mensual) {
            return $fecha->addMonthsNoOverflow($periodos);
        }

        $resultado = $fecha->startOfDay();
        for ($periodo = 0; $periodo < $periodos; $periodo++) {
            $resultado = self::siguienteDiaQuincenal($resultado);
        }

        return $resultado;
    }

    public static function esDiaQuincenal(CarbonImmutable $fecha): bool
    {
        return $fecha->day === 15 || $fecha->isLastOfMonth();
    }

    /** El siguiente 15 o último día del mes, estrictamente después de `$fecha`. */
    public static function siguienteDiaQuincenal(CarbonImmutable $fecha): CarbonImmutable
    {
        $fecha = $fecha->startOfDay();

        return match (true) {
            $fecha->day < 15 => $fecha->setDay(15),
            ! $fecha->isLastOfMonth() => $fecha->endOfMonth()->startOfDay(),
            default => $fecha->addDay()->setDay(15),
        };
    }
}
