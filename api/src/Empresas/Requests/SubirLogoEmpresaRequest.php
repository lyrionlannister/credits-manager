<?php

namespace App\Empresas\Requests;

use App\Empresas\Enums\VarianteLogoEmpresa;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubirLogoEmpresaRequest extends FormRequest
{
    /** Patrones de un SVG que ejecutaría script (navegación directa al archivo) o haría que `rsvg-convert` siga una referencia externa (SSRF). */
    private const PATRONES_SVG_PELIGROSOS = [
        '/<\s*script\b/i',
        '/<\s*foreignObject\b/i',
        '/\bon\w+\s*=/i',
        '/\b(?:xlink:)?href\s*=\s*["\']\s*(?:https?:|\/\/)/i',
    ];

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

    /** Un SVG con <script>, manejadores on* o referencias externas puede ejecutar código al abrirlo directo, o hacer que rsvg-convert siga la referencia (SSRF). */
    public function withValidator(ValidatorContract $validador): void
    {
        $validador->after(function (ValidatorContract $validador) {
            $logo = $this->file('logo');

            if (! $logo || $logo->getMimeType() !== 'image/svg+xml') {
                return;
            }

            $contenido = file_get_contents($logo->getRealPath());

            foreach (self::PATRONES_SVG_PELIGROSOS as $patron) {
                if (preg_match($patron, $contenido) === 1) {
                    $validador->errors()->add('logo', __('validation.custom.logo.svg_no_seguro'));

                    return;
                }
            }
        });
    }
}
