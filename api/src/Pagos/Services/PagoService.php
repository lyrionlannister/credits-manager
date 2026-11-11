<?php

namespace App\Pagos\Services;

use App\Auditoria\Constants\AuditoriaConstants;
use App\Auditoria\Enums\AccionAuditoria;
use App\Auditoria\Services\EmisorAuditoria;
use App\Contabilidad\Services\ComprobanteService;
use App\Core\Dinero\Dinero;
use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Models\Credito;
use App\Creditos\Models\Cuota;
use App\Creditos\Repositories\CreditoRepository;
use App\Creditos\Repositories\CuotaRepository;
use App\Creditos\Services\OtrosGastosCalculator;
use App\Exportacion\Enums\FormatoExportacion;
use App\Exportacion\Models\Exportacion;
use App\Exportacion\Services\ExportacionService;
use App\Facturacion\Services\DocumentoFacturacionService;
use App\Pagos\Enums\FormaPago;
use App\Pagos\Exportaciones\FuentePagos;
use App\Pagos\Models\Pago;
use App\Pagos\Models\ReversaPago;
use App\Pagos\Repositories\PagoDetalleRepository;
use App\Pagos\Repositories\PagoRepository;
use App\Pagos\Repositories\ReversaPagoRepository;
use App\Recibos\Services\ReciboCajaService;
use App\Usuarios\Models\Usuario;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PagoService
{
    public function __construct(
        protected PagoRepository $pagos,
        protected PagoDetalleRepository $pagoDetalles,
        protected ReversaPagoRepository $reversas,
        protected CuotaRepository $cuotas,
        protected CreditoRepository $creditos,
        protected ComprobanteService $comprobantes,
        protected ReciboCajaService $recibos,
        protected EmisorAuditoria $auditoria,
        protected ExportacionService $exportaciones,
        protected DocumentoFacturacionService $documentosFacturacion,
    ) {}

    public function solicitarExportacion(Usuario $usuario, ?string $busqueda, ?string $mes, FormatoExportacion $formato): Exportacion
    {
        return $this->exportaciones->solicitar(
            new FuentePagos((int) $usuario->empresa_id, $usuario->idAsesorConAlcance(), $busqueda, $mes),
            $formato,
            $usuario,
        );
    }

    public function listarPorCredito(int $creditoId, string $direccion = 'desc'): Collection
    {
        return $this->pagos->porCredito($creditoId, $direccion);
    }

    public function listar(?string $busqueda, ?string $mes, int $porPagina, int $pagina): LengthAwarePaginator
    {
        return $this->pagos->paginar($busqueda, $mes, max(1, min(100, $porPagina)), max(1, $pagina));
    }

    /**
     * Reversa un pago mediante movimiento compensatorio: el pago y su detalle no se tocan; las cuotas recuperan lo
     * aplicado, el recibo queda anulado (con su número) y el comprobante de recaudo se reversa con un asiento enlazado.
     * Solo admite el último pago aplicado del crédito: restaurar un pago anterior desharía la distribución de los
     * posteriores. Todo ocurre en una transacción con el crédito bloqueado.
     */
    public function reversar(Pago $pago, string $motivo, ?int $usuarioId): ReversaPago
    {
        return DB::transaction(function () use ($pago, $motivo, $usuarioId) {
            $credito = $this->creditos->bloquear($pago->credito_id);
            $this->exigirReversible($credito, $pago);

            $creditoAnteriores = $this->auditoria->instantanea($credito);
            $cuotasAfectadas = $pago->detalles()->exists()
                ? $this->restaurarCuotas($credito, $pago)
                : $this->restaurarCuotasDeLiquidacion($credito, $pago);

            $this->recalcularSaldoCapital($credito);

            if ($credito->estado === EstadoCredito::Pagado) {
                $this->creditos->actualizar($credito, ['estado' => EstadoCredito::Desembolsado]);
            }

            // El ajuste de otros gastos se rehace con el saldo restaurado: reproduce el valor que tenían antes del pago.
            $this->ajustarOtrosGastosRestantes($credito);

            $this->recibos->anularPorReversa($pago, $motivo, $usuarioId);

            $comprobante = $this->comprobantes->buscarContabilizadoPorOrigen('pagos', $pago->id);
            $reverso = $comprobante ? $this->comprobantes->reversarComprobante($comprobante, $motivo) : null;

            $reversa = $this->reversas->crear([
                'pago_id' => $pago->id,
                'comprobante_reverso_id' => $reverso?->id,
                'fecha' => now('America/Bogota')->toDateString(),
                'valor' => $pago->valor_pago,
                'motivo' => $motivo,
                'registrado_por' => $usuarioId,
            ]);

            $this->auditoria->registrar(
                AccionAuditoria::Reversar,
                AuditoriaConstants::MODULO_PAGOS,
                "Pago #{$pago->id} de {$pago->valor_pago->aDecimalString()} reversado en el crédito #{$credito->id}",
                'pago',
                $pago->id,
                nuevos: [
                    'reversa_id' => $reversa->id,
                    'comprobante_reverso_id' => $reverso?->id,
                    'cuotas_afectadas' => $cuotasAfectadas,
                ],
                motivo: $motivo,
            );
            $this->auditoria->registrarModificacion(
                AuditoriaConstants::MODULO_CREDITOS,
                "Crédito #{$credito->id} actualizado por la reversa del pago #{$pago->id}",
                $credito,
                $creditoAnteriores,
                $motivo,
            );

            return $reversa;
        });
    }

    /**
     * Aplica el pago a las cuotas pendientes/vencidas del crédito, de la más antigua a la más reciente. El
     * sobrante, si el pago excede lo adeudado, se descarta silenciosamente — no se guarda ni se abona a nada.
     */
    public function registrarPago(Credito $credito, Dinero $valorPago, FormaPago $formaPago, ?string $observacion, ?int $usuarioId, ?string $fechaPago = null): array
    {
        $this->exigirDesembolsado($credito);

        return DB::transaction(function () use ($credito, $valorPago, $formaPago, $observacion, $usuarioId, $fechaPago) {
            $creditoAnteriores = $this->auditoria->instantanea($credito);

            $pago = $this->pagos->crear([
                'credito_id' => $credito->id,
                'cliente_id' => $credito->cliente_id,
                'valor_pago' => $valorPago,
                'fecha_pago' => $fechaPago !== null ? CarbonImmutable::parse($fechaPago, 'America/Bogota')->startOfDay() : now(),
                'forma_pago' => $formaPago,
                'observacion' => $observacion,
                'registrado_por' => $usuarioId,
            ]);

            $aplicacion = $this->aplicarAlPlan($credito, $pago, $valorPago);
            $this->pagos->actualizar($pago, ['valor_capital' => $aplicacion['capital'], 'valor_interes' => $aplicacion['interes'], 'valor_otros_gastos' => $aplicacion['otros']]);
            $restante = $aplicacion['sobrante'];
            $capitalTotal = $aplicacion['capital'];
            $interesTotal = $aplicacion['interes'];
            $otrosTotal = $aplicacion['otros'];
            $cuotasAfectadas = $aplicacion['cuotas'];

            $this->recalcularSaldoCapital($credito);

            if ($this->cuotas->contarNoPagadas($credito->id) === 0) {
                $this->creditos->marcarComoPagado($credito);
            } else {
                $this->ajustarOtrosGastosRestantes($credito);
            }

            $comprobante = $this->comprobantes->contabilizarRecaudoPago($pago, $capitalTotal, $interesTotal, $otrosTotal, $credito->cliente->tercero_id);
            $recibo = $this->recibos->emitir($pago, $comprobante);

            $this->auditoria->registrarCreacion(
                AuditoriaConstants::MODULO_PAGOS,
                "Pago #{$pago->id} de {$valorPago->aDecimalString()} registrado en el crédito #{$credito->id}",
                $pago,
                [
                    'capital' => $capitalTotal,
                    'interes' => $interesTotal,
                    'otros_gastos' => $otrosTotal,
                    'sobrante_descartado' => $restante,
                    'cuotas_afectadas' => $cuotasAfectadas,
                    'comprobante_id' => $comprobante?->id,
                    'recibo' => $recibo->numero,
                ],
            );
            $this->auditoria->registrarModificacion(
                AuditoriaConstants::MODULO_CREDITOS,
                "Crédito #{$credito->id} saldado con el pago #{$pago->id}",
                $credito,
                $creditoAnteriores,
            );

            return ['pago' => $pago, 'sobrante' => $restante];
        });
    }

    /**
     * Cotiza el pago total anticipado del crédito (Ley 1555 de 2012: prepago sin
     * penalización, con "liquidación de intereses al día del pago"). Reemplaza por
     * completo el interés fijo del cronograma por un interés simple prorrateado por
     * días transcurridos desde el último pago (o desde el desembolso, si aún no ha
     * pagado nada) — los días transcurridos ya incluyen cualquier atraso, así que la
     * mora queda cubierta sin contarla dos veces. Pendiente de confirmar con el
     * cliente; ver alternativa híbrida comentada más abajo.
     *
     * `$fechaPago` permite registrar una liquidación que el cliente pagó antes de que se capturara: los días se
     * cuentan hasta esa fecha y no hasta hoy.
     */
    public function calcularLiquidacion(Credito $credito, ?string $fechaPago = null): array
    {
        $this->exigirDesembolsado($credito);

        $capitalPendiente = $this->capitalPendienteReal($credito);

        $fechaReferencia = $this->pagos->ultimoPago($credito->id)?->fecha_pago ?? $credito->fecha_desembolso;
        $fechaCorte = $fechaPago !== null ? CarbonImmutable::parse($fechaPago, 'America/Bogota')->startOfDay() : now();

        if ($fechaCorte->lt($fechaReferencia->copy()->startOfDay())) {
            throw ValidationException::withMessages(['fecha_pago' => __('validation.custom.fecha_pago.anterior_a_la_referencia')]);
        }

        $diasTranscurridos = (int) $fechaReferencia->diffInDays($fechaCorte);

        $divisorPeriodo = $credito->tipo_pago->diasPorPeriodo();
        $tasaDiaria = ((float) $credito->tasa_interes / 100) / $divisorPeriodo;

        $interes = $capitalPendiente->multiplicarPorTasa($tasaDiaria * $diasTranscurridos);
        $otrosGastos = $this->otrosGastosLiquidacion($credito);
        $total = $capitalPendiente->sumar($interes)->sumar($otrosGastos);

        /*
         * Alternativa híbrida (comentada, pendiente de confirmación del cliente):
         * en vez de reemplazar todo el interés del cronograma, cobrar el valor ya
         * fijado de las cuotas vencidas/pendientes (capital + interés tal cual, porque
         * ese período ya se cumplió) más el capital de las cuotas futuras (sin su
         * interés fijo, porque aún no se cumple el período) más el interés prorrateado
         * solo del período en curso (desde el vencimiento de la última cuota ya
         * cubierta, o desde el desembolso si aún no vence la primera).
         *
         * $fechaReferenciaHibrida = $credito->cuotas()
         *     ->where('estado', EstadoCuota::Pagada)
         *     ->orderByDesc('numero_cuota')
         *     ->value('fecha_vencimiento') ?? $credito->fecha_desembolso;
         * $diasTranscurridosHibrida = (int) $fechaReferenciaHibrida->diffInDays(now());
         *
         * $valorCuotasNoFuturas = $credito->cuotas()
         *     ->whereIn('estado', [EstadoCuota::Pendiente, EstadoCuota::Vencida])
         *     ->where('fecha_vencimiento', '<=', now())
         *     ->sum(DB::raw('valor - valor_pagado'));
         * $capitalCuotasFuturas = $credito->cuotas()
         *     ->where('fecha_vencimiento', '>', now())
         *     ->sum('valor_capital');
         *
         * $interesHibrido = round($capitalPendiente * $tasaDiaria * $diasTranscurridosHibrida, 2);
         * $totalHibrido = round($valorCuotasNoFuturas + $capitalCuotasFuturas + $interesHibrido, 2);
         */

        return [
            'capital_pendiente' => $capitalPendiente,
            'dias_transcurridos' => $diasTranscurridos,
            'tasa_diaria' => $tasaDiaria,
            'interes' => $interes,
            'otros_gastos' => $otrosGastos,
            'total' => $total,
            'fecha_referencia' => $fechaReferencia->toDateString(),
            'fecha_pago' => $fechaCorte->toDateString(),
        ];
    }

    public function liquidarCredito(Credito $credito, FormaPago $formaPago, ?string $observacion, ?int $usuarioId, ?string $fechaPago = null): array
    {
        return DB::transaction(function () use ($credito, $formaPago, $observacion, $usuarioId, $fechaPago) {
            $creditoAnteriores = $this->auditoria->instantanea($credito);
            $cotizacion = $this->calcularLiquidacion($credito, $fechaPago);

            $pago = $this->pagos->crear([
                'credito_id' => $credito->id,
                'cliente_id' => $credito->cliente_id,
                'valor_pago' => $cotizacion['total'],
                'valor_capital' => $cotizacion['capital_pendiente'],
                'valor_interes' => $cotizacion['interes'],
                'valor_otros_gastos' => $cotizacion['otros_gastos'],
                'fecha_pago' => $fechaPago !== null ? CarbonImmutable::parse($fechaPago, 'America/Bogota')->startOfDay() : now(),
                'forma_pago' => $formaPago,
                'observacion' => $observacion ?? 'Liquidación anticipada del crédito',
                'registrado_por' => $usuarioId,
            ]);

            $this->cuotas->cerrarPendientesComoOrigen($credito->id);
            $this->recalcularSaldoCapital($credito);
            $this->creditos->marcarComoPagado($credito);
            $comprobante = $this->comprobantes->contabilizarRecaudoPago($pago, $cotizacion['capital_pendiente'], $cotizacion['interes'], $cotizacion['otros_gastos'], $credito->cliente->tercero_id);
            $recibo = $this->recibos->emitir($pago, $comprobante);

            $this->auditoria->registrarCreacion(
                AuditoriaConstants::MODULO_PAGOS,
                "Liquidación anticipada del crédito #{$credito->id}: pago #{$pago->id} de {$cotizacion['total']->aDecimalString()}",
                $pago,
                [
                    'capital' => $cotizacion['capital_pendiente'],
                    'interes' => $cotizacion['interes'],
                    'comprobante_id' => $comprobante?->id,
                    'recibo' => $recibo->numero,
                ],
            );
            $this->auditoria->registrarModificacion(
                AuditoriaConstants::MODULO_CREDITOS,
                "Crédito #{$credito->id} saldado por liquidación anticipada",
                $credito,
                $creditoAnteriores,
            );

            return ['pago' => $pago, ...$cotizacion];
        });
    }

    /**
     * Pago de la importación histórica: fecha del archivo, sin recibo de caja. Solo contabiliza si la carga lo pidió.
     * Un valor que excede el saldo pendiente no se descarta: revierte la transacción con el mensaje correspondiente.
     *
     * @param  array<string, mixed>  $datosOrigen  texto del archivo que no es columna del pago (cuenta, registrado por)
     */
    public function registrarPagoHistorico(
        Credito $credito,
        Dinero $valor,
        string $fechaPago,
        array $datosOrigen,
        ?int $registradoPor,
        bool $contabilizar,
        ?int $terceroId = null,
    ): Pago {
        $this->exigirDesembolsado($credito);

        return DB::transaction(function () use ($credito, $valor, $fechaPago, $datosOrigen, $registradoPor, $contabilizar, $terceroId) {
            $creditoAnteriores = $this->auditoria->instantanea($credito);

            $pago = $this->pagos->crear([
                'credito_id' => $credito->id,
                'cliente_id' => $credito->cliente_id,
                'valor_pago' => $valor,
                'fecha_pago' => CarbonImmutable::parse($fechaPago, 'America/Bogota')->startOfDay(),
                'forma_pago' => FormaPago::Otro,
                'observacion' => $datosOrigen['observaciones'] ?? null,
                'registrado_por' => $registradoPor,
            ]);

            $aplicacion = $this->aplicarAlPlan($credito, $pago, $valor);
            $this->pagos->actualizar($pago, ['valor_capital' => $aplicacion['capital'], 'valor_interes' => $aplicacion['interes'], 'valor_otros_gastos' => $aplicacion['otros']]);

            if (! $aplicacion['sobrante']->esCero()) {
                throw ValidationException::withMessages(['valor' => 'El valor supera el saldo pendiente del crédito.']);
            }

            $this->recalcularSaldoCapital($credito);

            if ($this->cuotas->contarNoPagadas($credito->id) === 0) {
                $this->creditos->marcarComoPagado($credito);
            }

            $comprobante = $contabilizar
                ? $this->comprobantes->contabilizarRecaudoPago($pago, $aplicacion['capital'], $aplicacion['interes'], $aplicacion['otros'], $terceroId)
                : null;

            $this->auditoria->registrarCreacion(
                AuditoriaConstants::MODULO_PAGOS,
                "Pago #{$pago->id} de {$valor->aDecimalString()} importado en el crédito #{$credito->id}",
                $pago,
                [
                    'capital' => $aplicacion['capital'],
                    'interes' => $aplicacion['interes'],
                    'cuotas_afectadas' => $aplicacion['cuotas'],
                    'comprobante_id' => $comprobante?->id,
                    'importado' => true,
                    'origen_archivo' => $datosOrigen,
                ],
            );
            $this->auditoria->registrarModificacion(
                AuditoriaConstants::MODULO_CREDITOS,
                "Crédito #{$credito->id} actualizado por pago importado #{$pago->id}",
                $credito,
                $creditoAnteriores,
            );

            return $pago;
        });
    }

    /**
     * @return array{sobrante: Dinero, capital: Dinero, interes: Dinero, otros: Dinero, cuotas: list<int>}
     */
    private function aplicarAlPlan(Credito $credito, Pago $pago, Dinero $valor): array
    {
        $cuotas = $this->cuotas->pendientesOrdenadasParaActualizar($credito->id)->values();

        $aplicacion = AplicadorPagoPlan::aplicar(
            $cuotas->map(fn ($cuota) => [
                'valor' => $cuota->valor->sumar($cuota->valor_otros_gastos),
                'valor_pagado' => $cuota->valor_pagado,
                'valor_interes' => $cuota->valor_interes,
                'valor_otros_gastos' => $cuota->valor_otros_gastos,
            ])->all(),
            $valor,
        );

        $cuotasAfectadas = [];

        foreach ($aplicacion['asignaciones'] as $asignacion) {
            $cuota = $cuotas[$asignacion['posicion']];

            $this->pagoDetalles->crear([
                'pago_id' => $pago->id,
                'cuota_id' => $cuota->id,
                'valor_aplicado' => $asignacion['aplicado'],
            ]);

            $cuotasAfectadas[] = $cuota->id;

            $this->cuotas->actualizar($cuota, [
                'valor_pagado' => $asignacion['nuevo_pagado'],
                'estado' => $asignacion['nuevo_pagado']->mayorOIgualQue($cuota->valor->sumar($cuota->valor_otros_gastos)) ? EstadoCuota::Pagada : EstadoCuota::Pendiente,
            ]);
        }

        return [
            'sobrante' => $aplicacion['sobrante'],
            'capital' => $aplicacion['capital'],
            'interes' => $aplicacion['interes'],
            'otros' => $aplicacion['otros'],
            'cuotas' => $cuotasAfectadas,
        ];
    }

    /**
     * Otros gastos que cobra una cancelación anticipada (regla 12): las cuotas vencidas no pagadas cobran su saldo
     * completo de otros gastos; la cuota del periodo en curso cobra lo devengado por días desde el inicio de su periodo.
     * Las cuotas posteriores no generan otros gastos.
     */
    private function otrosGastosLiquidacion(Credito $credito): Dinero
    {
        $hoy = now()->startOfDay();
        $inicioPeriodo = $credito->fecha_desembolso;
        $total = Dinero::cero();

        foreach ($this->cuotas->todasPorCredito($credito->id) as $cuota) {
            $inicio = $inicioPeriodo;
            $inicioPeriodo = $cuota->fecha_vencimiento;

            if ($cuota->estado === EstadoCuota::Pagada) {
                continue;
            }

            $pendiente = $cuota->valor_otros_gastos->restar(AplicadorPagoPlan::desglosePagado($cuota->valor_pagado, $cuota->valor_interes, $cuota->valor_otros_gastos)['otros'])->noNegativo();

            if ($cuota->fecha_vencimiento->lt($hoy)) {
                $total = $total->sumar($pendiente);
            } elseif ($inicio->lte($hoy)) {
                $devengado = OtrosGastosCalculator::proporcionalPorDias($cuota->valor_otros_gastos, (int) $inicio->diffInDays(now()), $credito->tipo_pago);
                $total = $total->sumar($pendiente->menorEntre($devengado));
            }
        }

        return $total;
    }

    /**
     * Abono parcial que no liquida el crédito (regla 13): los otros gastos de las cuotas restantes pasan a
     * capital pendiente × porcentaje, es decir, capital inicial × porcentaje × (saldo / capital inicial).
     * Solo cambian las cuotas sin ningún pago; una cuota con pago parcial conserva su valor.
     */
    private function ajustarOtrosGastosRestantes(Credito $credito): void
    {
        $saldo = $this->capitalPendienteReal($credito);
        $otrosAjustados = OtrosGastosCalculator::valorPorCuota($saldo, (float) $credito->gastos_administracion_pct);

        foreach ($this->cuotas->pendientesOrdenadasParaActualizar($credito->id) as $cuota) {
            // Una cuota con pago parcial en curso conserva su valor: cambiarlo a mitad de pago alteraría lo ya abonado.
            if (! $cuota->valor_pagado->esCero()) {
                continue;
            }

            $this->cuotas->actualizar($cuota, ['valor_otros_gastos' => $otrosAjustados]);
        }
    }

    private function exigirReversible(Credito $credito, Pago $pago): void
    {
        if (! in_array($credito->estado, [EstadoCredito::Desembolsado, EstadoCredito::Pagado], true)) {
            throw ValidationException::withMessages(['pago' => __('validation.custom.reversa.estado_credito')]);
        }

        if ($this->reversas->existePara($pago->id)) {
            throw ValidationException::withMessages(['pago' => __('validation.custom.reversa.ya_reversado')]);
        }

        if ($this->pagos->ultimoVigenteDelCredito($credito->id)?->id !== $pago->id) {
            throw ValidationException::withMessages(['pago' => __('validation.custom.reversa.no_es_ultimo')]);
        }

        // Si el interés de alguna cuota de este pago ya se facturó ("Facturar intereses pagados"), reversar el
        // pago sin deshacer esa factura dejaría una cuota "sin pagar" con una factura de su interés ya emitida.
        if ($this->documentosFacturacion->existeInteresFacturadoParaPagoDetalle($pago->detalles()->pluck('id')->all())) {
            throw ValidationException::withMessages(['pago' => __('validation.custom.reversa.interes_ya_facturado')]);
        }
    }

    /**
     * Devuelve a cada cuota lo que este pago le aplicó. El estado vuelve a Pendiente o Vencida según la fecha, no a
     * «la del pago»: la mora es una consecuencia del vencimiento, no del último movimiento.
     *
     * @return list<int> ids de las cuotas afectadas
     */
    private function restaurarCuotas(Credito $credito, Pago $pago): array
    {
        $cuotas = $this->cuotas->todasOrdenadasParaActualizar($credito->id)->keyBy('id');
        $hoy = now('America/Bogota')->startOfDay();
        $afectadas = [];

        foreach ($pago->detalles()->get() as $detalle) {
            $cuota = $cuotas[$detalle->cuota_id];
            $pagado = $cuota->valor_pagado->restar($detalle->valor_aplicado)->noNegativo();

            $this->cuotas->actualizar($cuota, ['valor_pagado' => $pagado, 'estado' => $this->estadoSegunPagado($cuota, $pagado, $hoy)]);
            $afectadas[] = $cuota->id;
        }

        return $afectadas;
    }

    /**
     * Una liquidación cierra las cuotas sin dejar detalle por cuota, así que no hay qué restarle a cada una: lo que
     * cada cuota tenía antes se reconstruye con lo que le aplicaron los demás pagos vigentes del crédito.
     *
     * @return list<int> ids de las cuotas afectadas
     */
    private function restaurarCuotasDeLiquidacion(Credito $credito, Pago $pago): array
    {
        $aplicadoPorCuota = $this->pagoDetalles->sumaVigentePorCuota($credito->id, $pago->id);
        $hoy = now('America/Bogota')->startOfDay();
        $afectadas = [];

        foreach ($this->cuotas->todasOrdenadasParaActualizar($credito->id) as $cuota) {
            $pagado = Dinero::desdeCentavos($aplicadoPorCuota[$cuota->id] ?? 0);

            if ($pagado->igualA($cuota->valor_pagado)) {
                continue;
            }

            $this->cuotas->actualizar($cuota, ['valor_pagado' => $pagado, 'estado' => $this->estadoSegunPagado($cuota, $pagado, $hoy)]);
            $afectadas[] = $cuota->id;
        }

        return $afectadas;
    }

    /** El estado vuelve a Pendiente o Vencida según la fecha, no a «la del pago»: la mora es consecuencia del vencimiento. */
    private function estadoSegunPagado(Cuota $cuota, Dinero $pagado, CarbonInterface $hoy): EstadoCuota
    {
        return match (true) {
            $pagado->mayorOIgualQue($cuota->valor->sumar($cuota->valor_otros_gastos)) => EstadoCuota::Pagada,
            $cuota->fecha_vencimiento->lt($hoy) => EstadoCuota::Vencida,
            default => EstadoCuota::Pendiente,
        };
    }

    private function exigirDesembolsado(Credito $credito): void
    {
        if ($credito->estado === EstadoCredito::Refinanciado) {
            throw ValidationException::withMessages(['estado' => __('validation.custom.estado.refinanciado')]);
        }

        if ($credito->estado !== EstadoCredito::Desembolsado) {
            throw ValidationException::withMessages(['estado' => __('validation.custom.estado.no_desembolsado')]);
        }
    }

    /**
     * Capital realmente pendiente de todo el crédito (no de una cuota puntual), a partir
     * de lo efectivamente pagado (`valor_pagado - valor_interes`), no del cronograma
     * teórico que genera `AmortizacionCalculator`.
     */
    private function capitalPendienteReal(Credito $credito): Dinero
    {
        $capitalRestante = $credito->valor_prestamo;

        foreach ($this->cuotas->todasPorCredito($credito->id) as $cuota) {
            $capitalRestante = $capitalRestante->restar(AplicadorPagoPlan::desglosePagado($cuota->valor_pagado, $cuota->valor_interes, $cuota->valor_otros_gastos)['capital']);
        }

        return $capitalRestante->noNegativo();
    }

    /**
     * Recalcula `saldo_capital` de todas las cuotas del crédito con la misma derivación
     * que `capitalPendienteReal`, pero persistiendo el resultado cuota por cuota. Necesario
     * porque un abono parcial no amortiza capital al ritmo del cronograma, y el saldo
     * mostrado quedaría "a favor" del deudor si se dejara el valor fijo del desembolso.
     */
    private function recalcularSaldoCapital(Credito $credito): void
    {
        $capitalRestante = $credito->valor_prestamo;

        foreach ($this->cuotas->todasOrdenadasParaActualizar($credito->id) as $cuota) {
            $capitalPagado = AplicadorPagoPlan::desglosePagado($cuota->valor_pagado, $cuota->valor_interes, $cuota->valor_otros_gastos)['capital'];
            $capitalRestante = $capitalRestante->restar($capitalPagado);
            $this->cuotas->actualizar($cuota, ['saldo_capital' => $capitalRestante->noNegativo()]);
        }
    }
}
