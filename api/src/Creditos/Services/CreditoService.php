<?php

namespace App\Creditos\Services;

use App\Auditoria\Constants\AuditoriaConstants;
use App\Auditoria\Enums\AccionAuditoria;
use App\Auditoria\Services\EmisorAuditoria;
use App\Contabilidad\Services\ComprobanteService;
use App\Core\Dinero\Dinero;
use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Enums\TipoCredito;
use App\Creditos\Enums\TipoPago;
use App\Creditos\Enums\TipoReprogramacion;
use App\Creditos\Exportaciones\FuenteCreditos;
use App\Creditos\Models\Credito;
use App\Creditos\Repositories\CreditoRepository;
use App\Creditos\Repositories\CuotaRepository;
use App\Creditos\Repositories\ReprogramacionCreditoRepository;
use App\Exportacion\Enums\FormatoExportacion;
use App\Exportacion\Models\Exportacion;
use App\Exportacion\Services\ExportacionService;
use App\Pagos\Services\PagoService;
use App\Usuarios\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreditoService
{
    public function __construct(
        protected CreditoRepository $creditos,
        protected CuotaRepository $cuotas,
        protected CuotaService $cuotaService,
        protected ComprobanteService $comprobantes,
        protected EmisorAuditoria $auditoria,
        protected ExportacionService $exportaciones,
        protected PlanCuotasService $planCuotas,
        protected ReprogramacionCreditoRepository $reprogramaciones,
        protected PagoService $pagoService,
    ) {}

    /**
     * Reprograma el mismo crédito desembolsado: el capital de las cuotas que aún no tienen pagos ni interés devengado
     * se reparte en `cuotas_restantes` cuotas nuevas desde `fecha_primer_vencimiento` (tasa nueva si se envía). Las
     * cuotas pagadas, con abonos o con interés ya devengado no se tocan y se siguen debiendo. No crea otro crédito.
     */
    public function reprogramar(Credito $credito, array $datos): Credito
    {
        return DB::transaction(function () use ($credito, $datos) {
            $credito = $this->creditos->bloquear($credito->id);
            $this->exigirEstado($credito, EstadoCredito::Desembolsado);

            $anteriores = $this->auditoria->instantanea($credito);
            $planAnterior = $this->planCuotas->fotografiar($credito);

            $reprogramables = $this->cuotas->pendientesOrdenadasParaActualizar($credito->id)
                ->filter(fn ($cuota) => $cuota->valor_pagado->esCero() && $cuota->interes_devengado_en === null)
                ->values();

            if ($reprogramables->isEmpty()) {
                throw ValidationException::withMessages(['credito' => __('validation.custom.reprogramacion.sin_cuotas')]);
            }

            $capital = Dinero::sumarVarios(...$reprogramables->pluck('valor_capital')->all());
            $tasa = isset($datos['tasa_efectiva_anual']) || isset($datos['tasa_interes']) ? $this->tasaMensualDe($datos) : $credito->tasaMensual();
            $cuotasRestantes = (int) $datos['cuotas_restantes'];
            $primerVencimiento = CarbonImmutable::parse($datos['fecha_primer_vencimiento']);

            if ($credito->tipo_pago === TipoPago::Quincenal && ! AmortizacionCalculator::esDiaQuincenal($primerVencimiento)) {
                throw ValidationException::withMessages(['fecha_primer_vencimiento' => __('validation.custom.fecha_primer_vencimiento.dia_quincenal')]);
            }
            $fechas = array_map(
                fn (int $periodo) => AmortizacionCalculator::sumarPeriodos($primerVencimiento, $periodo, $credito->tipo_pago)->toDateString(),
                range(0, $cuotasRestantes - 1),
            );

            $calculo = $this->planCuotas->rehacerCuotas(
                $credito,
                $reprogramables,
                $capital,
                $tasa,
                $fechas,
                (int) $this->cuotas->todasPorCredito($credito->id)->max('numero_cuota') + 1,
                // Misma base que `conOtrosGastos()`, `actualizarGastosAdministracion()` y el accessor `valorCuotaTotal`
                // del modelo: el valor prestado original, nunca el saldo pendiente (si no, el total del crédito que
                // se muestra deja de coincidir con lo que quedó guardado en las cuotas nuevas).
                OtrosGastosCalculator::valorPorCuota($credito->valor_prestamo, (float) $credito->gastos_administracion_pct, $credito->tipo_pago),
            );
            $this->planCuotas->recalcularSaldoCapital($credito);
            $credito = $this->planCuotas->actualizarTotalesDelCredito($credito, $calculo['valor_cuota'], $tasa);
            if (isset($datos['tasa_efectiva_anual']) || isset($datos['tasa_interes'])) {
                $credito = $this->creditos->actualizar($credito, ['tasa_efectiva_anual' => $datos['tasa_efectiva_anual'] ?? null]);
            }

            $reprogramacion = $this->reprogramaciones->crear([
                'credito_id' => $credito->id,
                'tipo' => TipoReprogramacion::Reprogramacion,
                'motivo' => $datos['motivo'],
                'plan_anterior' => $planAnterior,
                'creado_por' => Auth::id(),
            ]);

            $this->auditoria->registrarModificacion(
                AuditoriaConstants::MODULO_CREDITOS,
                "Crédito #{$credito->id} reprogramado: {$cuotasRestantes} cuota(s) sobre {$capital->aDecimalString()} de capital",
                $credito,
                $anteriores,
                motivo: $datos['motivo'],
                adicionales: [
                    'reprogramacion_id' => $reprogramacion->id,
                    'capital_reprogramado' => $capital->aDecimalString(),
                    'cuotas_reemplazadas' => $reprogramables->count(),
                    'cuotas_nuevas' => $cuotasRestantes,
                    'efecto_contable' => 'ninguno: el capital adeudado no cambia, solo su calendario',
                ],
            );

            return $this->creditos->obtenerConRelaciones($credito);
        });
    }

    public function listarCreditos(?string $busqueda, int $porPagina, int $pagina, ?int $clienteId = null, ?EstadoCredito $estado = null): LengthAwarePaginator
    {
        $this->cuotaService->actualizarVencidas();

        $creditos = $this->creditos->paginar($busqueda, max(1, min(100, $porPagina)), max(1, $pagina), $clienteId, $estado);
        $resumenPorCredito = $this->cuotas->resumenPorCreditos($creditos->pluck('id')->all());

        $creditos->getCollection()->transform(function (Credito $credito) use ($resumenPorCredito) {
            $credito->saldo = Dinero::desdeCentavos($resumenPorCredito[$credito->id]['saldo'] ?? 0)->aDecimalString();

            return $credito;
        });

        return $creditos;
    }

    public function solicitarExportacion(Usuario $usuario, ?string $busqueda, ?EstadoCredito $estado, FormatoExportacion $formato): Exportacion
    {
        $this->cuotaService->actualizarVencidas();

        return $this->exportaciones->solicitar(
            new FuenteCreditos((int) $usuario->empresa_id, $usuario->idAsesorConAlcance(), $busqueda, $estado?->value),
            $formato,
            $usuario,
        );
    }

    public function crearCredito(array $datos): Credito
    {
        return DB::transaction(function () use ($datos) {
            [$valorPrestamo, $saldoTomado] = $this->valorDelPrestamo($datos);
            $this->exigirSaldoRefinanciadoVigente($datos, $saldoTomado);
            $porcentajeGastos = (float) ($datos['gastos_administracion_pct'] ?? 0);
            $tasa = $this->tasaMensualDe($datos);

            $credito = $this->creditos->crear([
                'cliente_id' => $datos['cliente_id'],
                'tipo_credito' => $datos['tipo_credito'],
                'credito_origen_id' => $datos['credito_origen_id'] ?? null,
                'valor_prestamo' => $valorPrestamo,
                'saldo_refinanciado' => $saldoTomado,
                'tasa_interes' => round($tasa, 6),
                'tasa_efectiva_anual' => $datos['tasa_efectiva_anual'] ?? null,
                'gastos_administracion_pct' => $porcentajeGastos,
                'plazo' => $datos['plazo'],
                'tipo_pago' => $datos['tipo_pago'],
                'valor_cuota' => 0,
                'valor_total' => 0,
                'fecha_desembolso' => $datos['fecha_desembolso'],
                'estado' => EstadoCredito::Solicitado,
                'creado_por' => Auth::id(),
            ]);

            $resultado = AmortizacionCalculator::calcular(
                $valorPrestamo,
                $tasa,
                (int) $datos['plazo'],
                TipoPago::from($datos['tipo_pago']),
                $datos['fecha_desembolso'],
                $datos['fecha_primer_vencimiento'] ?? null,
            );

            $this->cuotas->crearVarias($credito->id, $this->conOtrosGastos($resultado['cuotas'], $valorPrestamo, $porcentajeGastos, $credito->tipo_pago));

            $this->creditos->actualizar($credito, [
                'valor_cuota' => $resultado['valor_cuota'],
                'valor_total' => $resultado['valor_total'],
            ]);

            $this->auditoria->registrarCreacion(
                AuditoriaConstants::MODULO_CREDITOS,
                "Crédito #{$credito->id} creado para el cliente #{$credito->cliente_id}",
                $credito,
                ['cuotas' => count($resultado['cuotas'])],
            );

            return $this->creditos->obtenerConRelaciones($credito);
        });
    }

    /** Mismo cálculo que `crearCredito`, sin tocar la base de datos: nada se persiste. */
    public function previsualizarCredito(array $datos): array
    {
        [$valorPrestamo, $saldoTomado] = $this->valorDelPrestamo($datos);

        return $this->previsualizacion($valorPrestamo, $saldoTomado, (float) ($datos['gastos_administracion_pct'] ?? 0), $datos);
    }

    /** Mismo cálculo que `editarCondiciones`, sin tocar la base de datos: así se ve el plan antes de regenerarlo. */
    public function previsualizarEdicion(Credito $credito, array $datos): array
    {
        $this->exigirEditable($credito);

        return $this->previsualizacion(
            $this->valorPrestamoEditado($credito, $datos),
            $credito->saldo_refinanciado,
            (float) ($datos['gastos_administracion_pct'] ?? $credito->gastos_administracion_pct),
            $datos,
        );
    }

    private function previsualizacion(Dinero $valorPrestamo, ?Dinero $saldoTomado, float $porcentajeGastos, array $datos): array
    {
        $plazo = (int) $datos['plazo'];
        $tipoPago = TipoPago::from($datos['tipo_pago']);
        $resultado = AmortizacionCalculator::calcular(
            $valorPrestamo,
            $this->tasaMensualDe($datos),
            $plazo,
            $tipoPago,
            $datos['fecha_desembolso'],
            $datos['fecha_primer_vencimiento'] ?? null,
        );

        $otrosPorCuota = OtrosGastosCalculator::valorPorCuota($valorPrestamo, $porcentajeGastos, $tipoPago);

        return [
            'valor_prestamo' => $valorPrestamo,
            'saldo_refinanciado' => $saldoTomado,
            'valor_cuota' => $resultado['valor_cuota'],
            'valor_total' => $resultado['valor_total'],
            'valor_total_con_otros' => $resultado['valor_total']->sumar(Dinero::sumarVarios(...array_fill(0, $plazo, $otrosPorCuota))),
            'tasa_interes' => number_format($this->tasaMensualDe($datos), 6, '.', ''),
            'interes_total' => $resultado['valor_total']->restar($valorPrestamo),
            'cuotas' => $this->conValorTotalCuota($this->conOtrosGastos($resultado['cuotas'], $valorPrestamo, $porcentajeGastos, $tipoPago)),
        ];
    }

    /**
     * Solo mientras el crédito no se ha desembolsado: aún no hay pagos ni comprobante contable, así que
     * cambiar las condiciones se resuelve regenerando el plan de cuotas. Pasado el desembolso, cualquier
     * corrección va por refinanciación. La bitácora conserva valores anteriores y nuevos, motivo, usuario y fecha.
     */
    public function editarCondiciones(Credito $credito, array $datos): Credito
    {
        return DB::transaction(function () use ($credito, $datos) {
            $credito = $this->creditos->bloquear($credito->id);
            $this->exigirEditable($credito);
            $valorPrestamo = $this->valorPrestamoEditado($credito, $datos);

            $porcentajeGastos = (float) ($datos['gastos_administracion_pct'] ?? $credito->gastos_administracion_pct);

            $anteriores = $this->auditoria->instantanea($credito);
            $cuotasReemplazadas = $this->cuotas->todasOrdenadasParaActualizar($credito->id)->count();

            $tipoPago = TipoPago::from($datos['tipo_pago']);
            $resultado = AmortizacionCalculator::calcular(
                $valorPrestamo,
                $this->tasaMensualDe($datos),
                (int) $datos['plazo'],
                $tipoPago,
                $datos['fecha_desembolso'],
                $datos['fecha_primer_vencimiento'] ?? null,
            );

            $this->cuotas->eliminarTodasDeCredito($credito->id);
            $this->cuotas->crearVarias($credito->id, $this->conOtrosGastos($resultado['cuotas'], $valorPrestamo, $porcentajeGastos, $tipoPago));

            $credito = $this->creditos->actualizar($credito, [
                'valor_prestamo' => $valorPrestamo,
                'tasa_interes' => round($this->tasaMensualDe($datos), 6),
                'tasa_efectiva_anual' => $datos['tasa_efectiva_anual'] ?? null,
                'gastos_administracion_pct' => $porcentajeGastos,
                'plazo' => (int) $datos['plazo'],
                'tipo_pago' => $datos['tipo_pago'],
                'fecha_desembolso' => $datos['fecha_desembolso'],
                'valor_cuota' => $resultado['valor_cuota'],
                'valor_total' => $resultado['valor_total'],
            ]);

            $this->auditoria->registrarModificacion(
                AuditoriaConstants::MODULO_CREDITOS,
                "Condiciones del crédito #{$credito->id} modificadas",
                $credito,
                $anteriores,
                motivo: $datos['motivo'],
                adicionales: [
                    'cuotas_reemplazadas' => $cuotasReemplazadas,
                    'cuotas_generadas' => count($resultado['cuotas']),
                    'efecto_contable' => 'ninguno: el crédito aún no se desembolsa',
                ],
            );

            return $this->creditos->obtenerConRelaciones($credito);
        });
    }

    /**
     * Cambia el % de otros gastos de un crédito desembolsado y recalcula solo las cuotas no pagadas.
     * Capital, interés, `valor` y las cuotas pagadas no cambian; no se regenera la amortización.
     */
    public function actualizarGastosAdministracion(Credito $credito, float $porcentaje, string $motivo): Credito
    {
        return DB::transaction(function () use ($credito, $porcentaje, $motivo) {
            $credito = $this->creditos->bloquear($credito->id);
            $this->exigirEstado($credito, EstadoCredito::Desembolsado);

            $anteriores = $this->auditoria->instantanea($credito);
            $otrosGastos = OtrosGastosCalculator::valorPorCuota($credito->valor_prestamo, $porcentaje, $credito->tipo_pago);

            $pendientes = $this->cuotas->pendientesOrdenadasParaActualizar($credito->id);
            foreach ($pendientes as $cuota) {
                $this->cuotas->actualizar($cuota, ['valor_otros_gastos' => $otrosGastos]);
            }

            $credito = $this->creditos->actualizar($credito, ['gastos_administracion_pct' => $porcentaje]);

            $this->auditoria->registrarModificacion(
                AuditoriaConstants::MODULO_CREDITOS,
                "Otros gastos del crédito #{$credito->id} cambiados a {$porcentaje} %",
                $credito,
                $anteriores,
                motivo: $motivo,
                adicionales: [
                    'cuotas_recalculadas' => $pendientes->count(),
                    'valor_otros_gastos_por_cuota' => $otrosGastos->aDecimalString(),
                ],
            );

            return $this->creditos->obtenerConRelaciones($credito);
        });
    }

    private function exigirEditable(Credito $credito): void
    {
        if (! in_array($credito->estado, [EstadoCredito::Solicitado, EstadoCredito::Aprobado], true)) {
            throw ValidationException::withMessages(['credito' => __('validation.custom.credito.no_editable')]);
        }
    }

    /** En una refinanciación el préstamo es el saldo tomado al crearla (no el actual del original) más el valor adicional. */
    private function valorPrestamoEditado(Credito $credito, array $datos): Dinero
    {
        $valorPrestamo = $credito->tipo_credito === TipoCredito::Refinanciacion
            ? ($credito->saldo_refinanciado ?? Dinero::cero())->sumar(Dinero::desdePesos($datos['valor_adicional']))
            : Dinero::desdePesos($datos['valor_prestamo']);

        if (! $valorPrestamo->esPositivo()) {
            throw ValidationException::withMessages(['valor_adicional' => __('validation.custom.valor_adicional.sin_valor')]);
        }

        return $valorPrestamo;
    }

    /** La tasa se pacta en EA y la mensual sale de ella sin redondear; sin EA, la mensual enviada tal cual (importaciones, integraciones). */
    private function tasaMensualDe(array $datos): float
    {
        return isset($datos['tasa_efectiva_anual'])
            ? AmortizacionCalculator::tasaMensualDesdeEfectivaAnual((float) $datos['tasa_efectiva_anual'])
            : (float) $datos['tasa_interes'];
    }

    /** Cada cuota lleva el mismo valor de otros gastos: capital inicial × porcentaje, sin tocar capital ni interés. */
    private function conOtrosGastos(array $cuotas, Dinero $capitalInicial, float $porcentaje, TipoPago $tipoPago): array
    {
        $otrosGastos = OtrosGastosCalculator::valorPorCuota($capitalInicial, $porcentaje, $tipoPago);

        return array_map(fn (array $cuota) => [...$cuota, 'valor_otros_gastos' => $otrosGastos], $cuotas);
    }

    /** Replica el accesor `valor_total_cuota` de `Cuota` para que la previsualización use las mismas columnas. */
    private function conValorTotalCuota(array $cuotas): array
    {
        return array_map(fn (array $cuota) => [...$cuota, 'valor_total_cuota' => $cuota['valor']->sumar($cuota['valor_otros_gastos'])], $cuotas);
    }

    /**
     * Refinanciar es unificar el saldo de capital pendiente del original con el nuevo valor a
     * prestar en un solo crédito (definición de DDA). El saldo tomado se guarda como foto.
     *
     * @return array{0: Dinero, 1: ?Dinero} valor del préstamo y saldo tomado (nulo si no es refinanciación)
     */
    private function valorDelPrestamo(array $datos): array
    {
        if ($datos['tipo_credito'] !== TipoCredito::Refinanciacion->value) {
            return [Dinero::desdePesos($datos['valor_prestamo']), null];
        }

        $saldoTomado = $this->cuotaService->capitalPendiente($this->creditos->buscarPorIdOFallar($datos['credito_origen_id']));
        $valorPrestamo = $saldoTomado->sumar(Dinero::desdePesos($datos['valor_adicional']));

        if (! $valorPrestamo->esPositivo()) {
            throw ValidationException::withMessages(['valor_adicional' => __('validation.custom.valor_adicional.sin_valor')]);
        }

        return [$valorPrestamo, $saldoTomado];
    }

    /**
     * El saldo que el usuario vio y aprobó al previsualizar debe seguir vigente: si el original recibió un pago
     * entre la previsualización y esta creación, el saldo a refinanciar ya cambió y la refinanciación no se crea
     * con cifras distintas de las que el usuario confirmó.
     */
    private function exigirSaldoRefinanciadoVigente(array $datos, ?Dinero $saldoTomado): void
    {
        if ($saldoTomado === null) {
            return;
        }

        if (! $saldoTomado->igualA(Dinero::desdePesos($datos['saldo_refinanciado_previsualizado']))) {
            throw ValidationException::withMessages(['credito_origen_id' => __('validation.custom.credito_origen_id.saldo_cambio_desde_previsualizacion')]);
        }
    }

    /**
     * Vínculo entre el crédito original y su refinanciación, visible desde ambos lados en el
     * detalle y en el estado de cuenta. Requiere `obtenerConRelaciones` (usa las relaciones ya cargadas).
     *
     * @return array<string, mixed>|null
     */
    public function vinculoRefinanciacion(Credito $credito): ?array
    {
        if ($credito->tipo_credito === TipoCredito::Refinanciacion && $credito->credito_origen) {
            return [
                'tipo' => 'refinancia',
                'credito_relacionado' => $this->resumenDelVinculo($credito->credito_origen),
                'saldo_tomado' => $credito->saldo_refinanciado,
                'valor_adicional' => $credito->saldo_refinanciado === null ? null : $credito->valorDesembolsado(),
            ];
        }

        if ($credito->estado === EstadoCredito::Refinanciado && $credito->refinanciado_por) {
            return [
                'tipo' => 'refinanciado',
                'credito_relacionado' => $this->resumenDelVinculo($credito->refinanciado_por),
            ];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function resumenDelVinculo(Credito $credito): array
    {
        return [
            'id' => $credito->id,
            'estado' => $credito->estado,
            'valor_prestamo' => $credito->valor_prestamo,
            'fecha_desembolso' => $credito->fecha_desembolso,
        ];
    }

    public function mostrarCredito(Credito $credito): Credito
    {
        $this->cuotaService->actualizarVencidas();

        return $this->creditos->obtenerConRelaciones($credito);
    }

    public function aprobar(Credito $credito): Credito
    {
        $this->exigirEstado($credito, EstadoCredito::Solicitado);

        $anteriores = $this->auditoria->instantanea($credito);
        $credito = $this->creditos->actualizar($credito, ['estado' => EstadoCredito::Aprobado]);

        $this->auditoria->registrarModificacion(
            AuditoriaConstants::MODULO_CREDITOS,
            "Crédito #{$credito->id} aprobado",
            $credito,
            $anteriores,
            accion: AccionAuditoria::Aprobar,
        );

        return $credito;
    }

    /** Recién aquí se contabiliza el desembolso — antes de aprobar/desembolsar no ha salido dinero real. */
    public function desembolsar(Credito $credito): Credito
    {
        return DB::transaction(function () use ($credito) {
            $this->exigirEstado($credito, EstadoCredito::Aprobado);

            $origen = $this->origenParaCongelar($credito);
            $anteriores = $this->auditoria->instantanea($credito);

            $credito = $this->creditos->actualizar($credito, [
                'estado' => EstadoCredito::Desembolsado,
                'desembolsado_en' => now(),
            ]);
            $comprobante = $this->comprobantes->contabilizarDesembolsoCredito($credito, $credito->cliente->tercero_id);

            $this->auditoria->registrarModificacion(
                AuditoriaConstants::MODULO_CREDITOS,
                "Crédito #{$credito->id} desembolsado".($comprobante ? '' : ' sin contabilizar (sin dinero nuevo: el saldo venía de la refinanciación)'),
                $credito,
                $anteriores,
                accion: AccionAuditoria::Desembolsar,
                adicionales: ['comprobante_id' => $comprobante?->id, 'contabilizado' => $comprobante !== null],
            );

            if ($origen) {
                $this->congelarOrigen($origen, $credito);
            }

            return $credito;
        });
    }

    /** El original sigue recibiendo pagos hasta que se desembolsa la refinanciación; debe seguir desembolsado en ese momento. */
    private function origenParaCongelar(Credito $credito): ?Credito
    {
        if ($credito->tipo_credito !== TipoCredito::Refinanciacion || $credito->credito_origen_id === null) {
            return null;
        }

        $origen = $this->creditos->buscarParaCongelar($credito->credito_origen_id);

        if ($origen->estado !== EstadoCredito::Desembolsado) {
            throw ValidationException::withMessages(['credito_origen_id' => __('validation.custom.credito_origen_id.origen_no_desembolsado')]);
        }

        // El original siguió recibiendo pagos desde la solicitud: si su saldo cambió, la base de la refinanciación ya no es la acordada.
        if ($credito->saldo_refinanciado !== null && ! $this->cuotaService->capitalPendiente($origen)->igualA($credito->saldo_refinanciado)) {
            throw ValidationException::withMessages(['credito_origen_id' => __('validation.custom.credito_origen_id.saldo_cambio')]);
        }

        return $origen;
    }

    /** El original queda congelado como historial: sus cuotas no se tocan ni se inventan pagos. */
    private function congelarOrigen(Credito $origen, Credito $refinanciacion): void
    {
        $anteriores = $this->auditoria->instantanea($origen);

        $this->creditos->marcarComoRefinanciado($origen);

        $this->auditoria->registrarModificacion(
            AuditoriaConstants::MODULO_CREDITOS,
            "Crédito #{$origen->id} refinanciado por el crédito #{$refinanciacion->id}",
            $origen,
            $anteriores,
        );
    }

    /** Desembolsada, la refinanciación ya congeló al original: deshacerla dejaría a ambos sin cobrar. */
    private function exigirNoSerRefinanciacionDesembolsada(Credito $credito): void
    {
        if ($credito->tipo_credito === TipoCredito::Refinanciacion && $credito->estado === EstadoCredito::Desembolsado) {
            throw ValidationException::withMessages(['credito' => __('validation.custom.credito.refinanciacion_desembolsada')]);
        }
    }

    /**
     * Solicitado/Aprobado se pueden cancelar en cualquier momento (aún no hay dinero
     * real de por medio). Un crédito Desembolsado solo se puede cancelar dentro de
     * las 24 horas siguientes al desembolso — pasada esa ventana, la corrección pasa
     * por el flujo normal de pagos/liquidación, no por cancelación. Un crédito con al
     * menos un pago registrado tampoco se puede cancelar, sin importar cuánto tiempo
     * lleve desembolsado (mismo criterio que `eliminar()`): un pago ya es un hecho
     * financiero real, no algo que "cancelar" pueda deshacer. Si el crédito ya estaba
     * desembolsado, su comprobante de desembolso se reversa (nunca se edita ni se
     * borra) para que la contabilidad no quede con dinero "salido" de un crédito
     * cancelado.
     */
    public function cancelar(Credito $credito): Credito
    {
        return DB::transaction(function () use ($credito) {
            $this->exigirNoSerRefinanciacionDesembolsada($credito);

            $puedeCancelar = match ($credito->estado) {
                EstadoCredito::Solicitado, EstadoCredito::Aprobado => true,
                EstadoCredito::Desembolsado => $credito->desembolsado_en !== null
                    && $credito->desembolsado_en->diffInHours(now()) < 24,
                default => false,
            };

            if (! $puedeCancelar) {
                throw ValidationException::withMessages(['estado' => __('validation.custom.estado.transicion_invalida')]);
            }

            if ($credito->pagos()->exists()) {
                throw ValidationException::withMessages(['credito' => __('validation.custom.credito.cancelacion_con_pagos')]);
            }

            $anteriores = $this->auditoria->instantanea($credito);

            if ($credito->estado === EstadoCredito::Desembolsado) {
                $this->reversarDesembolsoSiExiste($credito, 'Cancelación del crédito #'.$credito->id.' dentro de las 24 horas del desembolso');
            }

            $credito = $this->creditos->actualizar($credito, ['estado' => EstadoCredito::Cancelado]);

            $this->auditoria->registrarModificacion(
                AuditoriaConstants::MODULO_CREDITOS,
                "Crédito #{$credito->id} cancelado",
                $credito,
                $anteriores,
                accion: AccionAuditoria::Cancelar,
            );

            return $credito;
        });
    }

    /**
     * Soft delete — nunca hard delete de un registro de negocio. Un crédito con pagos no es intocable: se
     * reversan todos (el más reciente primero, con el mismo movimiento compensatorio que una reversa manual —
     * los pagos y sus comprobantes quedan en la bitácora, nunca se editan ni se borran) y luego, si estaba
     * desembolsado, su propio comprobante de desembolso, igual que hace `cancelar()`. Si algún pago no se puede
     * reversar (interés ya facturado, reprogramación posterior), esa validación aborta todo: nada queda a medias.
     */
    public function eliminar(Credito $credito): bool
    {
        return DB::transaction(function () use ($credito) {
            $credito = $this->creditos->bloquear($credito->id);

            if ($credito->estado === EstadoCredito::Refinanciado) {
                throw ValidationException::withMessages(['credito' => __('validation.custom.credito.refinanciado_no_eliminable')]);
            }

            $this->exigirNoSerRefinanciacionDesembolsada($credito);

            $motivo = 'Eliminación del crédito #'.$credito->id;
            $this->pagoService->reversarTodos($credito, $motivo, Auth::id());
            $credito->refresh();

            if ($credito->estado === EstadoCredito::Desembolsado) {
                $this->reversarDesembolsoSiExiste($credito, $motivo);
            }

            $this->cuotas->eliminarTodasDeCredito($credito->id);

            $eliminado = $this->creditos->eliminar($credito);

            $this->auditoria->registrarEliminacion(AuditoriaConstants::MODULO_CREDITOS, "Crédito #{$credito->id} eliminado", $credito);

            return $eliminado;
        });
    }

    private function reversarDesembolsoSiExiste(Credito $credito, string $motivo): void
    {
        $comprobante = $this->comprobantes->buscarContabilizadoPorOrigen('creditos', $credito->id);

        if ($comprobante) {
            $this->comprobantes->reversarComprobante($comprobante, $motivo);
        }
    }

    private function exigirEstado(Credito $credito, EstadoCredito $esperado): void
    {
        if ($credito->estado !== $esperado) {
            throw ValidationException::withMessages(['estado' => __('validation.custom.estado.transicion_invalida')]);
        }
    }
}
