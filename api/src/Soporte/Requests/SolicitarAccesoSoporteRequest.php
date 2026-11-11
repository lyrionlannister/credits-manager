<?php

namespace App\Soporte\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SolicitarAccesoSoporteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->es_superadmin ?? false;
    }

    public function rules(): array
    {
        return [
            'empresa_id' => ['required', 'integer', 'exists:empresas,id'],
            'motivo' => ['required', 'string', 'max:500'],
            'duracion_minutos' => ['nullable', 'integer', 'min:5', 'max:120'],
        ];
    }
}
