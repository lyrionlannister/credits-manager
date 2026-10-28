<?php

namespace App\Facturacion\Services;

use App\Causacion\Repositories\TarifaReteIcaMunicipioRepository;
use App\Causacion\Repositories\ValorUvtRepository;
use App\Core\Dinero\Dinero;
use App\Empresas\Models\Empresa;
use App\Facturacion\Models\ParametrizacionConceptoFacturacion;
use App\Terceros\Enums\TipoPersona;
use App\Terceros\Models\Tercero;

/**
 * IVA y retenciones de una línea de factura. A diferencia del motor de Gastos (`MotorReglasFiscalesService`), aquí
 * quien recibe el pago es la empresa (DDA), no el tercero: por eso el IVA y la autorretención dependen de los
 * atributos fiscales de la empresa, y la retención solo se practica si el cliente califica como agente retenedor
 * (persona jurídica) — una persona natural normal no retiene en Colombia. Si la empresa es autorretenedora, nadie
 * más retiene sobre lo que se le paga.
 */
class MotorFiscalFacturacionService
{
    public function __construct(
        protected ValorUvtRepository $valoresUvt,
        protected TarifaReteIcaMunicipioRepository $tarifasReteIca,
    ) {}

    /** @return array{valor_iva: Dinero, valor_retefuente: Dinero, valor_reteica: Dinero, valor_reteiva: Dinero} */
    public function evaluar(Dinero $valor, Empresa $empresa, Tercero $tercero, ?ParametrizacionConceptoFacturacion $parametrizacion, string $fecha): array
    {
        if ($parametrizacion === null) {
            return $this->sinImpuestos();
        }

        $valorIva = $this->calcularIva($valor, $empresa, $parametrizacion);

        if (! $this->clienteCalificaComoAgenteRetenedor($empresa, $tercero)) {
            return [...$this->sinRetenciones(), 'valor_iva' => $valorIva];
        }

        return [
            'valor_iva' => $valorIva,
            'valor_retefuente' => $this->calcularRetefuente($valor, $parametrizacion, $fecha),
            'valor_reteica' => $this->calcularReteica($valor, $empresa, $parametrizacion, $fecha),
            'valor_reteiva' => $this->calcularReteiva($valorIva, $parametrizacion),
        ];
    }

    private function calcularIva(Dinero $valor, Empresa $empresa, ParametrizacionConceptoFacturacion $parametrizacion): Dinero
    {
        if (! $empresa->responsable_iva || $parametrizacion->tarifa_iva === null) {
            return Dinero::cero();
        }

        return $valor->multiplicarPorTasa((float) $parametrizacion->tarifa_iva / 100);
    }

    /** Persona natural: casi nunca es agente retenedor en Colombia. Empresa autorretenedora: nadie más retiene. */
    private function clienteCalificaComoAgenteRetenedor(Empresa $empresa, Tercero $tercero): bool
    {
        return ! $empresa->autorretenedor && $tercero->tipo_persona === TipoPersona::Juridica;
    }

    private function calcularRetefuente(Dinero $valor, ParametrizacionConceptoFacturacion $parametrizacion, string $fecha): Dinero
    {
        if (! $parametrizacion->aplica_retefuente || ! $parametrizacion->tarifa_retefuente) {
            return Dinero::cero();
        }

        if ($parametrizacion->base_minima_uvt !== null) {
            $uvt = $this->valoresUvt->vigenteParaFecha($fecha);
            $baseMinima = $uvt?->valor->multiplicarPorTasa((float) $parametrizacion->base_minima_uvt) ?? Dinero::cero();

            if ($valor->menorQue($baseMinima)) {
                return Dinero::cero();
            }
        }

        return $valor->multiplicarPorTasa((float) $parametrizacion->tarifa_retefuente / 100);
    }

    /** El ICA lo genera quien presta el servicio: la actividad económica y el municipio son los de la empresa. */
    private function calcularReteica(Dinero $valor, Empresa $empresa, ParametrizacionConceptoFacturacion $parametrizacion, string $fecha): Dinero
    {
        if (! $parametrizacion->aplica_reteica || ! $empresa->actividad_economica_id || ! $empresa->municipio_id) {
            return Dinero::cero();
        }

        $tarifa = $this->tarifasReteIca->vigenteParaFecha($empresa->municipio_id, $empresa->actividad_economica_id, $fecha);

        if ($tarifa === null) {
            return Dinero::cero();
        }

        return $valor->multiplicarPorTasa((float) $tarifa->tarifa / 1000);
    }

    private function calcularReteiva(Dinero $valorIva, ParametrizacionConceptoFacturacion $parametrizacion): Dinero
    {
        if (! $parametrizacion->aplica_reteiva || ! $valorIva->esPositivo() || ! $parametrizacion->tarifa_reteiva) {
            return Dinero::cero();
        }

        return $valorIva->multiplicarPorTasa((float) $parametrizacion->tarifa_reteiva / 100);
    }

    /** @return array{valor_iva: Dinero, valor_retefuente: Dinero, valor_reteica: Dinero, valor_reteiva: Dinero} */
    private function sinImpuestos(): array
    {
        return ['valor_iva' => Dinero::cero(), ...$this->sinRetenciones()];
    }

    /** @return array{valor_retefuente: Dinero, valor_reteica: Dinero, valor_reteiva: Dinero} */
    private function sinRetenciones(): array
    {
        return ['valor_retefuente' => Dinero::cero(), 'valor_reteica' => Dinero::cero(), 'valor_reteiva' => Dinero::cero()];
    }
}
