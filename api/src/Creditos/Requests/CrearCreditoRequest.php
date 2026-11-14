<?php

namespace App\Creditos\Requests;

use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Enums\TipoCredito;
use App\Creditos\Enums\TipoPago;
use App\Creditos\Repositories\CreditoRepository;
use App\Creditos\Rules\VencimientoQuincenalValido;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CrearCreditoRequest extends FormRequest
{
    public function __construct(protected CreditoRepository $creditos)
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
            'cliente_id' => [
                'required', 'integer',
                Rule::exists('clientes', 'id')->where(fn (Builder $consulta) => $consulta
                    ->where('empresa_id', $this->user()->empresa_id)
                    ->when($this->user()->restringidoAClientesPropios(), fn (Builder $propios) => $propios->where('asesor_id', $this->user()->id))),
            ],
            'tipo_credito' => ['required', Rule::enum(TipoCredito::class)],
            'credito_origen_id' => [
                'nullable', 'required_if:tipo_credito,refinanciacion', 'integer',
                Rule::exists('creditos', 'id')->where(fn (Builder $consulta) => $consulta
                    ->where('empresa_id', $this->user()->empresa_id)
                    ->when($this->user()->restringidoAClientesPropios(), fn (Builder $propios) => $propios
                        ->whereIn('cliente_id', DB::table('clientes')->select('id')->where('asesor_id', $this->user()->id)))),
            ],
            // En una refinanciación el valor del préstamo lo calcula el servidor: saldo de capital del original + `valor_adicional`.
            'valor_prestamo' => ['required_unless:tipo_credito,refinanciacion', 'prohibited_if:tipo_credito,refinanciacion', 'numeric', 'min:0.01'],
            'valor_adicional' => ['required_if:tipo_credito,refinanciacion', 'prohibited_unless:tipo_credito,refinanciacion', 'numeric', 'min:0'],
            // Lo que la previsualización mostró como saldo de capital del original: si cambió (p. ej. un pago entre
            // previsualizar y crear), el servidor rechaza la creación en vez de guardar un saldo distinto del aprobado.
            'saldo_refinanciado_previsualizado' => ['required_if:tipo_credito,refinanciacion', 'prohibited_unless:tipo_credito,refinanciacion', 'numeric', 'min:0'],
            'tasa_interes' => ['required', 'numeric', 'min:0'],
            'gastos_administracion_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'plazo' => ['required', 'integer', 'min:1'],
            'tipo_pago' => ['required', Rule::enum(TipoPago::class)],
            'fecha_desembolso' => ['required', 'date'],
            'fecha_primer_vencimiento' => ['nullable', 'date_format:Y-m-d', 'after:fecha_desembolso', new VencimientoQuincenalValido($this->input('tipo_pago'))],
        ];
    }

    public function withValidator(ValidatorContract $validador): void
    {
        $validador->after(function (ValidatorContract $validador) {
            if ($this->input('tipo_credito') !== TipoCredito::Refinanciacion->value || ! $this->filled('credito_origen_id')) {
                return;
            }

            $origen = $this->creditos->buscarPorId($this->input('credito_origen_id'));

            if ($origen && (string) $origen->cliente_id !== (string) $this->input('cliente_id')) {
                $validador->errors()->add('credito_origen_id', __('validation.custom.credito_origen_id.pertenece_a_otro_cliente'));
            }

            if ($origen && $origen->estado !== EstadoCredito::Desembolsado) {
                $validador->errors()->add('credito_origen_id', __('validation.custom.credito_origen_id.no_desembolsado'));
            }

            if ($origen && $this->creditos->existeRefinanciacionEnCurso($origen->id)) {
                $validador->errors()->add('credito_origen_id', __('validation.custom.credito_origen_id.en_curso'));
            }
        });
    }
}
