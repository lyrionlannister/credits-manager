<?php

namespace App\Importacion\Procesadores;

use App\Contabilidad\Services\ComprobanteService;
use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Enums\TipoCredito;
use App\Creditos\Enums\TipoPago;
use App\Creditos\Repositories\CreditoRepository;
use App\Creditos\Repositories\CuotaRepository;
use App\Creditos\Services\AmortizacionCalculator;
use App\Creditos\Services\OtrosGastosCalculator;
use App\Importacion\Data\ContextoEjecucion;
use App\Importacion\Data\FilaRechazada;
use App\Importacion\Lectura\ConvertidorValor;
use App\Importacion\Repositories\DatosImportacionRepository;
use Carbon\CarbonImmutable;

/**
 * Créditos históricos, ya desembolsados. El plan se calcula con el motor francés; si el archivo trae la cuota fija y
 * no coincide, se usa la calculada y se informa la diferencia. Plan_Cuotas, si viene, reemplaza ese plan. Con asiento,
 * cada crédito se contabiliza como su desembolso real: fecha de desembolso y cuentas de la regla de desembolso.
 */
final class ProcesadorCreditos implements ProcesadorFila
{
    public function __construct(
        private readonly DatosImportacionRepository $datos,
        private readonly CreditoRepository $creditos,
        private readonly CuotaRepository $cuotas,
        private readonly ComprobanteService $comprobantes,
    ) {}

    public function precargar(array $valoresLote, ContextoEjecucion $ctx): void
    {
        $ctx->clientesPorCedula = $this->datos->clientesPorCedulas(
            $ctx->empresaId,
            array_values(array_filter(array_column($valoresLote, 'cedula'))),
        );
        $ctx->creditosPorCodigo = $this->datos->creditosPorCodigos(
            $ctx->empresaId,
            array_values(array_filter(array_column($valoresLote, 'codigo'))),
        );
        $ctx->tercerosPorClienteId = $this->datos->tercerosPorClientes(
            $ctx->empresaId,
            array_values($ctx->clientesPorCedula),
        );
    }

    public function procesar(array $valores, ContextoEjecucion $ctx): void
    {
        $codigo = $this->requerido($valores['codigo'], 'codigo', 'El código de crédito es obligatorio.');
        $cedula = $this->requerido($valores['cedula'], 'cedula', 'La cédula del cliente es obligatoria.');

        $fecha = ConvertidorValor::fecha($valores['fecha_desembolso'], 'fecha_desembolso')
            ?? throw new FilaRechazada('La fecha de desembolso es obligatoria.', 'fecha_desembolso');
        $valor = ConvertidorValor::monto($valores['valor'], 'valor')
            ?? throw new FilaRechazada('El valor desembolsado es obligatorio.', 'valor');
        $tasa = ConvertidorValor::decimal($valores['tasa'], 'tasa')
            ?? throw new FilaRechazada('La tasa de interés es obligatoria.', 'tasa');
        $plazo = ConvertidorValor::entero($valores['plazo'], 'plazo')
            ?? throw new FilaRechazada('El plazo es obligatorio.', 'plazo');
        $cuotaArchivo = ConvertidorValor::monto($valores['cuota'], 'cuota');
        $tipoPago = $this->periodicidad($valores['periodicidad']);
        $gastosAdministracion = ConvertidorValor::decimal($valores['gastos_administracion'] ?? '', 'gastos_administracion');
        $porcentajeGastos = $gastosAdministracion !== null ? (float) $gastosAdministracion : 0.0;

        if ($gastosAdministracion !== null && ($porcentajeGastos < 0 || $porcentajeGastos > 100)) {
            throw new FilaRechazada('El porcentaje de gastos administrativos debe estar entre 0 y 100.', 'gastos_administracion');
        }

        if (! $valor->esPositivo()) {
            throw new FilaRechazada('El valor desembolsado debe ser mayor que cero.', 'valor');
        }

        if ((float) $tasa <= 0 || $plazo < 1) {
            throw new FilaRechazada('La tasa y el plazo deben ser mayores que cero.', 'plazo');
        }

        if (ConvertidorValor::siNo($valores['es_refinanciacion'], 'es_refinanciacion') === true) {
            throw new FilaRechazada('Las refinanciaciones no se importan en esta carga: regístralas desde Créditos.', 'es_refinanciacion');
        }

        if ($valores['codigo_anterior'] !== '' || $valores['saldo_refinanciado'] !== '') {
            throw new FilaRechazada('El código anterior y el saldo refinanciado solo aplican a refinanciaciones, que no se importan en esta carga.', 'codigo_anterior');
        }

        if (! $ctx->primeraVez('credito|'.$codigo)) {
            throw new FilaRechazada('El código de crédito está repetido en el archivo.', 'codigo');
        }

        if (isset($ctx->creditosPorCodigo[$codigo])) {
            throw new FilaRechazada('Ya existe un crédito con este código: ya fue importado.', 'codigo');
        }

        $clienteId = $ctx->clientesPorCedula[$cedula]
            ?? throw new FilaRechazada("No existe un cliente con cédula {$cedula}. Importa primero la hoja Clientes.", 'cedula');

        $calculo = AmortizacionCalculator::calcular($valor, (float) $tasa, $plazo, $tipoPago, $fecha);

        $avisos = [];
        if ($cuotaArchivo !== null && ! $cuotaArchivo->igualA($calculo['valor_cuota'])) {
            $avisos[] = ['severidad' => 'aviso', 'columna' => 'cuota', 'motivo' => "Cuota del archivo {$cuotaArchivo->aDecimalString()} y calculada {$calculo['valor_cuota']->aDecimalString()}: se usa la calculada (diferencia de redondeo)."];
        }
        if ($valores['estado'] !== '') {
            $avisos[] = ['severidad' => 'aviso', 'columna' => 'estado', 'motivo' => 'El estado se calcula con los pagos: el valor del archivo no se guarda.'];
        }

        if ($ctx->escribir) {
            $credito = $this->creditos->crear([
                'cliente_id' => $clienteId,
                'codigo' => $codigo,
                'tipo_credito' => TipoCredito::Nuevo,
                'valor_prestamo' => $valor,
                'tasa_interes' => $tasa,
                'gastos_administracion_pct' => $porcentajeGastos,
                'plazo' => $plazo,
                'tipo_pago' => $tipoPago,
                'valor_cuota' => $calculo['valor_cuota'],
                'valor_total' => $calculo['valor_total'],
                'fecha_desembolso' => $fecha,
                'desembolsado_en' => CarbonImmutable::parse($fecha, 'America/Bogota')->startOfDay(),
                'estado' => EstadoCredito::Desembolsado,
                'creado_por' => $ctx->usuario->id,
            ]);

            $cuotasConOtrosGastos = array_map(
                fn (array $cuota) => [...$cuota, 'valor_otros_gastos' => OtrosGastosCalculator::valorPorCuota($valor, $porcentajeGastos, $tipoPago)],
                $calculo['cuotas'],
            );

            $this->cuotas->crearVarias($credito->id, $cuotasConOtrosGastos);
            $ctx->creditosPorCodigo[$codigo] = $credito->id;

            if ($ctx->generarAsiento && $ctx->cubiertoPorApertura($fecha)) {
                $ctx->reportes[] = ['severidad' => 'aviso', 'columna' => 'fecha_desembolso', 'motivo' => "Sin asiento de desembolso: los saldos iniciales con corte {$ctx->fechaCorteApertura} ya incluyen esta cartera."];
            } elseif ($ctx->generarAsiento) {
                $this->comprobantes->contabilizarDesembolsoCredito($credito, $ctx->tercerosPorClienteId[$clienteId] ?? null);
            }
        }

        if (! $ctx->escribir) {
            array_push($ctx->reportes, ...$avisos);
        }
    }

    private function requerido(string $valor, string $columna, string $motivo): string
    {
        if ($valor === '') {
            throw new FilaRechazada($motivo, $columna);
        }

        return $valor;
    }

    private function periodicidad(string $valor): TipoPago
    {
        $texto = ConvertidorValor::nombreNormalizado($valor);

        return match ($texto) {
            'MENSUAL' => TipoPago::Mensual,
            'QUINCENAL' => TipoPago::Quincenal,
            default => throw new FilaRechazada('Periodicidad no reconocida: «'.$valor.'». Usa Mensual o Quincenal.', 'periodicidad'),
        };
    }
}
