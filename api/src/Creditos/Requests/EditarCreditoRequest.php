<?php

namespace App\Creditos\Requests;

use App\Creditos\Enums\TipoCredito;
use App\Creditos\Enums\TipoPago;
use App\Creditos\Models\Credito;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EditarCreditoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Credito $credito */
        $credito = $this->route('credito');
        $esRefinanciacion = $credito->tipo_credito === TipoCredito::Refinanciacion;

        return [
            // En una refinanciación el préstamo es el saldo tomado del original más el valor adicional.
            'valor_prestamo' => $esRefinanciacion ? ['prohibited'] : ['required', 'numeric', 'min:0.01'],
            'valor_adicional' => $esRefinanciacion ? ['required', 'numeric', 'min:0'] : ['prohibited'],
            'tasa_interes' => ['required', 'numeric', 'min:0'],
            'gastos_administracion_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'plazo' => ['required', 'integer', 'min:1'],
            'tipo_pago' => ['required', Rule::enum(TipoPago::class)],
            'fecha_desembolso' => ['required', 'date'],
            'fecha_primer_vencimiento' => ['nullable', 'date_format:Y-m-d', 'after:fecha_desembolso'],
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }
}
