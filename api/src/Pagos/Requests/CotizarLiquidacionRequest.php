<?php

namespace App\Pagos\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CotizarLiquidacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha_pago' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}
