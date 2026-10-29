<?php

namespace App\Empresas\Requests;

use App\Empresas\Enums\VarianteLogoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubirLogoEmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'logo' => ['required', 'image:allow_svg', 'mimes:png,jpg,jpeg,gif,svg', 'max:2048'],
            'variante' => ['required', Rule::enum(VarianteLogoEmpresa::class)],
        ];
    }
}
