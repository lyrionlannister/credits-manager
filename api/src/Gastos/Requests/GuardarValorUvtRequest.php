<?php

namespace App\Gastos\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardarValorUvtRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha_desde' => ['required', 'date'],
            'valor' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
