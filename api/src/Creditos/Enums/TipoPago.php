<?php

namespace App\Creditos\Enums;

enum TipoPago: string
{
    case Mensual = 'mensual';
    case Quincenal = 'quincenal';

    /** Divisor de la tasa por periodo para prorratear por días (convención de intereses de liquidación). */
    public function diasPorPeriodo(): int
    {
        return match ($this) {
            self::Mensual => 30,
            self::Quincenal => 15,
        };
    }

    /** Cuántas cuotas cobran un mes de amortización: la tasa y los gastos de administración son mensuales. */
    public function cuotasPorMes(): int
    {
        return intdiv(self::Mensual->diasPorPeriodo(), $this->diasPorPeriodo());
    }
}
