<?php

namespace App\Creditos\Rules;

use App\Creditos\Enums\TipoPago;
use App\Creditos\Services\AmortizacionCalculator;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** En un crédito quincenal las cuotas vencen el 15 y el último día del mes: el primer vencimiento también. */
class VencimientoQuincenalValido implements ValidationRule
{
    public function __construct(private readonly mixed $tipoPago) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->tipoPago !== TipoPago::Quincenal->value || ! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return;
        }

        if (! AmortizacionCalculator::esDiaQuincenal(CarbonImmutable::parse($value))) {
            $fail('validation.custom.fecha_primer_vencimiento.dia_quincenal')->translate();
        }
    }
}
