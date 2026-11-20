<?php

namespace App\Creditos\Requests;

use App\Pagos\Repositories\PagoRepository;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

class ReprogramarCreditoRequest extends FormRequest
{
    public function __construct(protected PagoRepository $pagos)
    {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cuotas_restantes' => ['required', 'integer', 'min:1', 'max:240'],
            'tasa_efectiva_anual' => ['nullable', 'prohibits:tasa_interes', 'numeric', 'min:0', 'max:999'],
            'tasa_interes' => ['nullable', 'numeric', 'min:0'],
            'fecha_primer_vencimiento' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * El primer vencimiento puede quedar en el pasado para alinear créditos viejos con lo que ocurrió, pero no antes
     * del último pago vigente ni del desembolso: las cuotas nuevas no pueden vencer antes de lo ya pagado.
     */
    public function withValidator(ValidatorContract $validador): void
    {
        $validador->after(function (ValidatorContract $validador) {
            $credito = $this->route('credito');

            if (! $credito || $validador->errors()->has('fecha_primer_vencimiento')) {
                return;
            }

            $primerVencimiento = $this->date('fecha_primer_vencimiento')->startOfDay();
            $ultimoPago = $this->pagos->ultimoPago($credito->id)?->fecha_pago;

            if ($credito->fecha_desembolso && $primerVencimiento->lte($credito->fecha_desembolso->copy()->startOfDay())) {
                $validador->errors()->add('fecha_primer_vencimiento', __('validation.custom.fecha_primer_vencimiento.no_posterior_al_desembolso'));

                return;
            }

            if ($ultimoPago && $primerVencimiento->lt($ultimoPago->copy()->startOfDay())) {
                $validador->errors()->add('fecha_primer_vencimiento', __('validation.custom.fecha_primer_vencimiento.anterior_al_ultimo_pago'));
            }
        });
    }
}
