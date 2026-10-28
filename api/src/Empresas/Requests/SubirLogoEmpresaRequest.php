<?php

namespace App\Empresas\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
