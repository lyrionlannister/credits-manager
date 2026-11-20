<?php

namespace App\Creditos\Services;

use App\Core\Dinero\Dinero;
use App\Creditos\Constants\CreditoConstants;
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
 * `$tasaInteres` siempre es la tasa mensual y la amortización siempre es mensual (así amortiza DDA): en Quincenal cada
 * mes se cobra en dos cuotas iguales, la mitad de la cuota y del interés de ese mes. No hay tasa quincenal ni
 * amortización quincenal independiente.
 *
 * Cada mes cobra su interés completo sin importar los días reales entre el desembolso y la primera
 * cuota: las fechas solo deciden cuándo vence cada cuota, nunca cuánto cobra. El mes se suma sin desbordar (un
 * desembolso del 31 de enero vence el 28 de febrero, no el 3 de marzo).
 *
 * Quincenal: las cuotas vencen en días fijos, el 15 y el último día de cada mes (decisión de DDA), no cada 15 días.
 * El primer vencimiento es el siguiente de esos días después del desembolso, o el que le sigue si quedan menos de
 * `CreditoConstants::DIAS_MINIMOS_PRIMERA_CUOTA_QUINCENAL` días. Las cuotas se agrupan por pares desde la primera;
 * con plazo impar (reprogramación, abono a capital) el último grupo es medio mes.
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
        $cuotasPorMes = $tipoPago->cuotasPorMes();
        $meses = $plazo / $cuotasPorMes;

        $cuotaMensual = $i > 0
            ? $valorPrestamo->multiplicarPorTasa($i / (1 - (1 + $i) ** -$meses))
            : Dinero::sumarVarios(...array_fill(0, $cuotasPorMes, $valorPrestamo->dividirEntre($plazo)));

        $desembolso = CarbonImmutable::parse($fechaDesembolso)->startOfDay();
        $primerVencimiento = match (true) {
            $fechaPrimerVencimiento !== null => CarbonImmutable::parse($fechaPrimerVencimiento)->startOfDay(),
            $tipoPago === TipoPago::Quincenal => self::primerVencimientoQuincenal($desembolso),
            default => self::sumarPeriodos($desembolso, 1, $tipoPago),
        };

        if ($tipoPago === TipoPago::Quincenal && ! self::esDiaQuincenal($primerVencimiento)) {
            throw new InvalidArgumentException('El primer vencimiento quincenal debe caer el 15 o el último día del mes.');
        }

        $fechas = [];
        for ($n = 1; $n <= $plazo; $n++) {
            $fechas[$n] = $tipoPago === TipoPago::Mensual && $fechaPrimerVencimiento === null
                ? self::sumarPeriodos($desembolso, $n, $tipoPago)
                : self::sumarPeriodos($primerVencimiento, $n - 1, $tipoPago);
        }

        $saldo = $valorPrestamo;
        $valorTotal = Dinero::cero();
        $cuotas = [];

        foreach (array_chunk(range(1, $plazo), $cuotasPorMes) as $numeros) {
            $esUltimoMes = end($numeros) === $plazo;
            $interesMes = $saldo->multiplicarPorTasa($i * count($numeros) / $cuotasPorMes);

            $capitalMes = $esUltimoMes ? $saldo : $cuotaMensual->restar($interesMes);
            // Se parte la cuota del mes y el capital absorbe el centavo: así las dos quincenas cobran lo mismo.
            $valores = self::repartir($capitalMes->sumar($interesMes), count($numeros));
            $intereses = self::repartir($interesMes, count($numeros));

            foreach ($numeros as $posicion => $n) {
                $capitalEstaCuota = $valores[$posicion]->restar($intereses[$posicion]);
                $saldo = $saldo->restar($capitalEstaCuota);
                $valorTotal = $valorTotal->sumar($valores[$posicion]);

                $cuotas[] = [
                    'numero_cuota' => $n,
                    'fecha_vencimiento' => $fechas[$n]->format('Y-m-d'),
                    'valor' => $valores[$posicion],
                    'valor_interes' => $intereses[$posicion],
                    'valor_capital' => $capitalEstaCuota,
                    'saldo_capital' => $saldo->noNegativo(),
                ];
            }
        }

        return [
            'valor_cuota' => $cuotaMensual->dividirEntre($cuotasPorMes),
            'valor_total' => $valorTotal,
            'cuotas' => $cuotas,
        ];
    }

    /** Tasa mensual equivalente a la efectiva anual, en % y sin redondear: (1 + EA)^(1/12) − 1, igual a `NOMINAL(EA; 12) / 12`. */
    public static function tasaMensualDesdeEfectivaAnual(float $efectivaAnual): float
    {
        return ((1 + $efectivaAnual / 100) ** (1 / 12) - 1) * 100;
    }

    /** Partes iguales truncadas a centavo; la última absorbe el residuo para que la suma sea exacta. */
    private static function repartir(Dinero $valor, int $partes): array
    {
        $parte = $valor->dividirEntre($partes);
        $resto = $valor->restar(Dinero::sumarVarios(...array_fill(0, $partes, $parte)));

        return [...array_fill(0, $partes - 1, $parte), $parte->sumar($resto)];
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

    /** El siguiente 15 o fin de mes, salvo que quede demasiado cerca del desembolso: entonces el que le sigue. */
    public static function primerVencimientoQuincenal(CarbonImmutable $desembolso): CarbonImmutable
    {
        $desembolso = $desembolso->startOfDay();
        $siguiente = self::siguienteDiaQuincenal($desembolso);

        return $desembolso->diffInDays($siguiente) < CreditoConstants::DIAS_MINIMOS_PRIMERA_CUOTA_QUINCENAL
            ? self::siguienteDiaQuincenal($siguiente)
            : $siguiente;
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
