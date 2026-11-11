<?php

namespace App\Creditos\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReprogramarCreditoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cuotas_restantes' => ['required', 'integer', 'min:1', 'max:240'],
            'tasa_interes' => ['nullable', 'numeric', 'min:0'],
            'fecha_primer_vencimiento' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
