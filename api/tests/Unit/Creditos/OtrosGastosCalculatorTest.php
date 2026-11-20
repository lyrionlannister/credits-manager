<?php

namespace Tests\Unit\Creditos;

use App\Core\Dinero\Dinero;
use App\Creditos\Enums\TipoPago;
use App\Creditos\Services\OtrosGastosCalculator;
use PHPUnit\Framework\TestCase;

class OtrosGastosCalculatorTest extends TestCase
{
    public function test_el_valor_por_cuota_es_el_capital_inicial_por_el_porcentaje(): void
    {
        $valor = OtrosGastosCalculator::valorPorCuota(Dinero::desdePesos(2500000), 0.2, TipoPago::Mensual);

        $this->assertSame(500000, $valor->centavos());
    }

    /** Tabla de DDA: 2 % mensual sobre 22.000.000 son 440.000 al mes, 220.000 en cada quincena. */
    public function test_en_quincenal_el_porcentaje_mensual_se_reparte_entre_las_dos_cuotas_del_mes(): void
    {
        $valor = OtrosGastosCalculator::valorPorCuota(Dinero::desdePesos(22000000), 2, TipoPago::Quincenal);

        $this->assertSame(22000000, $valor->centavos());
    }

    public function test_redondea_a_centavo_a_mitad_hacia_arriba(): void
    {
        $valor = OtrosGastosCalculator::valorPorCuota(Dinero::desdeCentavos(10100), 0.5, TipoPago::Mensual);

        $this->assertSame(51, $valor->centavos());
    }

    public function test_un_porcentaje_cero_da_cero(): void
    {
        $this->assertTrue(OtrosGastosCalculator::valorPorCuota(Dinero::desdePesos(1000000), 0.0, TipoPago::Quincenal)->esCero());
    }

    public function test_el_prorrateo_mensual_usa_los_dias_sobre_treinta(): void
    {
        $valor = OtrosGastosCalculator::proporcionalPorDias(Dinero::desdePesos(5000), 15, TipoPago::Mensual);

        $this->assertSame(250000, $valor->centavos());
    }

    public function test_el_prorrateo_quincenal_usa_los_dias_sobre_quince(): void
    {
        $valor = OtrosGastosCalculator::proporcionalPorDias(Dinero::desdePesos(5000), 7, TipoPago::Quincenal);

        $this->assertSame(233333, $valor->centavos());
    }

    public function test_el_prorrateo_no_pasa_del_periodo_completo(): void
    {
        $valor = OtrosGastosCalculator::proporcionalPorDias(Dinero::desdePesos(5000), 45, TipoPago::Mensual);

        $this->assertSame(500000, $valor->centavos());
    }
}
