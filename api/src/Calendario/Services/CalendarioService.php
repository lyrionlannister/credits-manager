<?php

namespace App\Calendario\Services;

use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Models\Cuota;
use App\Creditos\Repositories\CreditoRepository;
use App\Creditos\Repositories\CuotaRepository;
use App\Creditos\Services\CuotaService;
use Carbon\Carbon;

/**
 * Sin tabla propia — muestra todas las cuotas (pagadas o no) del mes que el calendario
 * tiene abierto, cada una con el color que le corresponde pintar en el front. El color
 * se decide aquí y no en el cliente para que la regla de negocio (qué cuenta como
 * vencida) viva en un solo lugar, la misma que ya usa `CuotaRepository::marcarVencidas`.
 */
class CalendarioService
{
    public function __construct(
        protected CuotaService $cuotaService,
        protected CuotaRepository $cuotas,
        protected CreditoRepository $creditos,
    ) {}

    /**
     * @param  string|null  $mes  Formato `YYYY-MM`. Si no se indica, usa el mes actual.
     */
    public function agenda(?string $mes = null): array
    {
        $this->cuotaService->actualizarVencidas();

        $inicioMes = $mes ? Carbon::createFromFormat('Y-m-d', $mes.'-01')->startOfMonth() : now()->startOfMonth();
        $desde = $inicioMes->toDateString();
        $hasta = $inicioMes->copy()->endOfMonth()->toDateString();

        $cuotas = $this->cuotas->delMesDeCreditosActivos($desde, $hasta);
        $conteos = $this->creditos->conteoPorClientes(
            $cuotas->map(fn (Cuota $cuota) => $cuota->credito->cliente_id)->unique()->values()->all(),
        );

        return [
            'cuotas' => $cuotas
                ->map(function (Cuota $cuota) use ($conteos) {
                    $cliente = $cuota->credito->cliente;
                    $tercero = $cliente->tercero;

                    return [
                        'id' => $cuota->id,
                        'credito_id' => $cuota->credito_id,
                        'cliente_nombre' => $tercero->nombre,
                        'numero_cuota' => $cuota->numero_cuota,
                        'saldo' => $cuota->valor_total_cuota->restar($cuota->valor_pagado),
                        'valor_total' => $cuota->valor_total_cuota,
                        'valor_pagado' => $cuota->valor_pagado,
                        'valor_capital' => $cuota->valor_capital,
                        'valor_interes' => $cuota->valor_interes,
                        'valor_otros_gastos' => $cuota->valor_otros_gastos,
                        'fecha_vencimiento' => $cuota->fecha_vencimiento->toDateString(),
                        'estado' => $cuota->estado,
                        'color' => $this->colorPara($cuota->estado),
                        'cliente' => [
                            'id' => $cliente->id,
                            'nombre' => $tercero->nombre,
                            'tipo_documento' => $tercero->tipo_documento->value,
                            'numero_documento' => $tercero->numero_documento,
                            'celular' => $tercero->celular,
                        ],
                        'creditos_con_entidad' => $conteos[$cliente->id] ?? 0,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    private function colorPara(EstadoCuota $estado): string
    {
        return match ($estado) {
            EstadoCuota::Pagada => 'success',
            EstadoCuota::Vencida => 'danger',
            EstadoCuota::Pendiente => 'warning',
        };
    }
}
