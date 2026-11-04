<?php

namespace App\Pagos\Requests;

use App\Pagos\Enums\FormaPago;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CrearPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'valor_pago' => ['required', 'numeric', 'min:0.01'],
            'forma_pago' => ['required', Rule::enum(FormaPago::class)],
            'fecha_pago' => ['nullable', 'date', 'before_or_equal:today'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Sin esto, un pago se podría fechar antes del desembolso del crédito, abriendo de paso
     * cualquier período contable anterior (nace Abierto por defecto) sin pasar por un cierre.
     */
    public function withValidator(ValidatorContract $validador): void
    {
        $validador->after(function (ValidatorContract $validador) {
            $credito = $this->route('credito');

            if ($credito && $this->filled('fecha_pago') && $this->date('fecha_pago')->lt($credito->fecha_desembolso)) {
                $validador->errors()->add('fecha_pago', __('validation.custom.fecha_pago.anterior_al_desembolso'));
            }
        });
    }
}
