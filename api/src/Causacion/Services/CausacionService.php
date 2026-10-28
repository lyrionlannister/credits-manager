<?php

namespace App\Causacion\Services;

use App\Causacion\Models\ParametrizacionTipoGasto;
use App\Causacion\Models\TipoGasto;
use App\Causacion\Repositories\ParametrizacionTipoGastoRepository;
use App\Causacion\Repositories\TarifaReteIcaMunicipioRepository;
use App\Causacion\Repositories\TipoGastoRepository;
use App\Causacion\Repositories\ValorUvtRepository;
use App\Contabilidad\Models\Comprobante;
use App\Contabilidad\Services\ComprobanteService;
use App\Core\Dinero\Dinero;
use App\Terceros\Models\Tercero;
use App\Terceros\Repositories\TerceroRepository;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Motor compartido de "causación por concepto": dado un tipo de gasto ya parametrizado con cuentas y tarifas,
 * calcula el IVA y las retenciones (vía `MotorReglasFiscalesService`) y arma las líneas de comprobante listas
 * para `ComprobanteService::crearComprobante()`. Lo usan tanto `GastoService::causar()` (que además crea su
 * propia fila `Gasto`) como `causar()` de aquí mismo (para el comprobante manual, que no crea ninguna fila de
 * dominio — el comprobante y, si hay tercero, el `DocumentoTercero` que genera son el único registro).
 */
class CausacionService
{
    public function __construct(
        protected TipoGastoRepository $tiposGasto,
        protected ParametrizacionTipoGastoRepository $parametrizaciones,
        protected ValorUvtRepository $valoresUvt,
        protected TarifaReteIcaMunicipioRepository $tarifasReteIca,
        protected TerceroRepository $terceros,
        protected MotorReglasFiscalesService $motor,
        protected ComprobanteService $comprobantes,
    ) {}

    /**
     * Resuelve la parametrización vigente, la UVT vigente y (si aplica) la tarifa ReteICA, y evalúa el motor
     * fiscal. Lanza las mismas `ValidationException` que ya usaba `GastoService::causar()`.
     *
     * @return array{parametrizacion: ParametrizacionTipoGasto, calculo: array, retenciones_por_tipo: Collection}
     */
    public function calcular(TipoGasto $tipoGasto, ?Tercero $tercero, string $fecha, Dinero $valorBruto, ?int $municipioId): array
    {
        $parametrizacion = $this->parametrizaciones->vigenteParaFecha($tipoGasto->id, $fecha);
        if (! $parametrizacion) {
            throw ValidationException::withMessages([
                'tipo_gasto_id' => __('validation.custom.parametrizacion.no_vigente'),
            ]);
        }

        $uvt = $this->valoresUvt->vigenteParaFecha($fecha);
        if (! $uvt) {
            throw ValidationException::withMessages([
                'fecha' => __('validation.custom.valor_uvt.no_configurado'),
            ]);
        }

        $tarifaReteIca = null;
        if ($parametrizacion->aplica_reteica) {
            if (! $municipioId || ! $tercero?->actividad_economica_id) {
                throw ValidationException::withMessages([
                    'municipio_id' => __('validation.custom.tarifa_reteica.no_vigente'),
                ]);
            }

            $tarifaReteIca = $this->tarifasReteIca->vigenteParaFecha($municipioId, $tercero->actividad_economica_id, $fecha);
        }

        $calculo = $this->motor->evaluar($valorBruto, $parametrizacion, $tercero, $tarifaReteIca, $uvt);

        return [
            'parametrizacion' => $parametrizacion,
            'calculo' => $calculo,
            'retenciones_por_tipo' => collect($calculo['retenciones'])->keyBy(fn (array $r) => $r['tipo']->value),
        ];
    }

    /**
     * Arma las líneas débito/crédito CxP/retenciones de una causación. `$centroCostoId` va en la línea de gasto;
     * `$terceroId`/`$fechaVencimiento` van en la línea de CxP, que toma como documento cruce el número del propio
     * comprobante de causación (`documento_cruce_propio`) cuando hay tercero: ese es el documento que luego se paga.
     *
     * @param  Collection<string, array>  $retencionesPorTipo
     */
    public function construirLineas(
        ParametrizacionTipoGasto $parametrizacion,
        Dinero $valorBruto,
        array $calculo,
        Collection $retencionesPorTipo,
        ?int $centroCostoId,
        ?int $terceroId = null,
        ?string $fechaVencimiento = null,
    ): array {
        $lineas = [
            ['cuenta_id' => $parametrizacion->cuenta_gasto_id, 'debito' => $valorBruto, 'credito' => 0, 'centro_costo_id' => $centroCostoId],
        ];

        if ($calculo['valor_iva']->esPositivo()) {
            $lineas[] = ['cuenta_id' => $parametrizacion->cuenta_iva_id, 'debito' => $calculo['valor_iva'], 'credito' => 0];
        }

        $lineas[] = [
            'cuenta_id' => $parametrizacion->cuenta_cxp_id,
            'debito' => 0,
            'credito' => $calculo['valor_neto'],
            'tercero_id' => $terceroId,
            'documento_cruce_propio' => $terceroId !== null,
            'fecha_vencimiento' => $fechaVencimiento,
        ];

        $cuentasPorTipo = [
            'retefuente' => $parametrizacion->cuenta_retefuente_id,
            'reteica' => $parametrizacion->cuenta_reteica_id,
            'reteiva' => $parametrizacion->cuenta_reteiva_id,
        ];

        foreach ($cuentasPorTipo as $tipo => $cuentaId) {
            $retencion = $retencionesPorTipo[$tipo];
            if ($retencion['incluida'] && $retencion['valor']->esPositivo()) {
                $lineas[] = ['cuenta_id' => $cuentaId, 'debito' => 0, 'credito' => $retencion['valor']];
            }
        }

        return $lineas;
    }

    /**
     * Causa un concepto directamente como comprobante manual (sin crear ninguna fila `Gasto`): lo usa
     * `ComprobanteController::causarConcepto()`. El tipo de comprobante es el que ya trae la parametrización
     * vigente, igual que en Gastos — el formulario no pide uno nuevo.
     */
    public function causar(array $datos): Comprobante
    {
        $tipoGasto = $this->tiposGasto->activo($datos['tipo_gasto_id']);
        if (! $tipoGasto) {
            throw ValidationException::withMessages([
                'tipo_gasto_id' => __('validation.custom.tipo_gasto.inactivo_o_no_existe'),
            ]);
        }

        $tercero = isset($datos['tercero_id']) ? $this->terceros->buscarPorIdOFallar($datos['tercero_id']) : null;
        if ($tipoGasto->requiere_tercero && ! $tercero) {
            throw ValidationException::withMessages([
                'tercero_id' => __('validation.custom.tipo_gasto.requiere_tercero'),
            ]);
        }

        $valorBruto = Dinero::desdePesos($datos['valor_bruto']);
        $resultado = $this->calcular($tipoGasto, $tercero, $datos['fecha'], $valorBruto, $datos['municipio_id'] ?? null);
        $parametrizacion = $resultado['parametrizacion'];

        $lineas = $this->construirLineas(
            $parametrizacion,
            $valorBruto,
            $resultado['calculo'],
            $resultado['retenciones_por_tipo'],
            $datos['centro_costo_id'] ?? null,
            terceroId: $tercero?->id,
            fechaVencimiento: $datos['fecha_vencimiento'] ?? null,
        );

        return $this->comprobantes->crearComprobante(
            $parametrizacion->tipo_comprobante_id,
            $datos['fecha'],
            $lineas,
            $datos['concepto'] ?? $tipoGasto->nombre,
            'manual',
            null,
            $datos['numero_documento'],
            $tercero?->id,
            contabilizarInmediato: false,
        );
    }
}
