<?php

namespace App\Pagos\Requests;

use App\Pagos\Enums\FormaPago;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Que la fecha no sea anterior al último pago ni al desembolso lo valida `PagoService::calcularLiquidacion`. */
class LiquidarCreditoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'forma_pago' => ['required', Rule::enum(FormaPago::class)],
            'fecha_pago' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ];
    }
}
