<?php

namespace Tests\Concerns;

use App\Causacion\Models\ParametrizacionTipoGasto;
use App\Causacion\Models\TarifaReteIcaMunicipio;
use App\Causacion\Models\TipoGasto;
use App\Causacion\Models\ValorUvt;
use App\Contabilidad\Models\CentroCosto;
use App\Gastos\Models\ActividadEconomica;
use App\Geografia\Models\Municipio;
use App\Terceros\Enums\TipoPersona;
use App\Terceros\Models\Tercero;

/** Fixtures del módulo de Gastos — evita repetir el armado de tipo de gasto + parametrización vigente en cada test. */
trait CreatesGastosFixtures
{
    use CreatesGeografiaFixtures;

    protected function crearTipoGasto(array $overrides = []): TipoGasto
    {
        static $contador = 0;
        $contador++;

        return TipoGasto::create(array_merge([
            'codigo' => "TG{$contador}",
            'nombre' => "Tipo de gasto de prueba {$contador}",
            'requiere_tercero' => true,
            'aplica_iva' => false,
        ], $overrides));
    }

    protected function crearActividadEconomica(array $overrides = []): ActividadEconomica
    {
        static $contador = 0;
        $contador++;

        return ActividadEconomica::create(array_merge([
            'codigo' => "A{$contador}",
            'nombre' => "Actividad económica de prueba {$contador}",
        ], $overrides));
    }

    /** Centro de costo activo de la empresa de la sesión: los gastos nuevos lo exigen. */
    protected function crearCentroCosto(array $overrides = []): CentroCosto
    {
        static $contador = 0;
        $contador++;

        return CentroCosto::create(array_merge([
            'codigo' => "CC{$contador}",
            'nombre' => "Centro de costo de prueba {$contador}",
            'activo' => true,
        ], $overrides));
    }

    /** Vigencia abierta (sin `fecha_hasta`) desde el 1 de enero del año dado. */
    protected function crearValorUvt(int $anio = 2026, float $valor = 47065): ValorUvt
    {
        return ValorUvt::create(['fecha_desde' => "{$anio}-01-01", 'valor' => $valor]);
    }

    /** Ficha de parametrización con todas las cuentas necesarias ya creadas, vigente desde 2026-01-01 salvo que se indique otra fecha. */
    protected function crearParametrizacion(TipoGasto $tipoGasto, array $overrides = []): ParametrizacionTipoGasto
    {
        return ParametrizacionTipoGasto::create(array_merge([
            'tipo_gasto_id' => $tipoGasto->id,
            'fecha_desde' => '2026-01-01',
            'tipo_comprobante_id' => $this->crearTipoComprobante()->id,
            'cuenta_gasto_id' => $this->crearCuenta()->id,
            'cuenta_cxp_id' => $this->crearCuenta()->id,
            'aplica_retefuente' => false,
            'aplica_reteica' => false,
            'aplica_reteiva' => false,
        ], $overrides));
    }

    protected function crearTarifaReteIca(Municipio $municipio, ActividadEconomica $actividad, float $tarifa, array $overrides = []): TarifaReteIcaMunicipio
    {
        return TarifaReteIcaMunicipio::create(array_merge([
            'municipio_id' => $municipio->id,
            'actividad_economica_id' => $actividad->id,
            'fecha_desde' => '2026-01-01',
            'tarifa' => $tarifa,
        ], $overrides));
    }

    protected function crearTerceroFiscal(array $overrides = []): Tercero
    {
        return $this->crearTercero(array_merge([
            'tipo_persona' => TipoPersona::Natural,
            'autorretenedor' => false,
            'responsable_iva' => false,
        ], $overrides));
    }
}
