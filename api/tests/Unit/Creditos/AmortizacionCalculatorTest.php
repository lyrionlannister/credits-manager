<?php

namespace Tests\Unit\Creditos;

use App\Core\Dinero\Dinero;
use App\Creditos\Enums\TipoPago;
use App\Creditos\Services\AmortizacionCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class AmortizacionCalculatorTest extends TestCase
{
    /**
     * Caso de referencia calculado a mano (1000 al 10% mensual, 3 cuotas):
     * cuota fija 402.11, última cuota 402.13 (absorbe el redondeo del saldo).
     * Con `Dinero` (centavos enteros) el resultado es exacto, así que se compara
     * con `assertSame` sobre centavos en vez de `assertEqualsWithDelta` — si
     * volviera a hacer falta un delta, sería señal de que quedó un float colado.
     */
    public function test_sistema_frances_con_interes_calculado_a_mano(): void
    {
        $resultado = AmortizacionCalculator::calcular(Dinero::desdePesos(1000), 10, 3, TipoPago::Mensual, '2026-01-01');

        $this->assertSame(40211, $resultado['valor_cuota']->centavos());
        $this->assertSame(120635, $resultado['valor_total']->centavos());
        $this->assertCount(3, $resultado['cuotas']);

        [$c1, $c2, $c3] = $resultado['cuotas'];

        $this->assertCuota(['numero_cuota' => 1, 'fecha_vencimiento' => '2026-02-01', 'valor' => 40211, 'valor_interes' => 10000, 'valor_capital' => 30211, 'saldo_capital' => 69789], $c1);
        $this->assertCuota(['numero_cuota' => 2, 'fecha_vencimiento' => '2026-03-01', 'valor' => 40211, 'valor_interes' => 6979, 'valor_capital' => 33232, 'saldo_capital' => 36557], $c2);
        $this->assertCuota(['numero_cuota' => 3, 'fecha_vencimiento' => '2026-04-01', 'valor' => 40213, 'valor_interes' => 3656, 'valor_capital' => 36557, 'saldo_capital' => 0], $c3);
    }

    private function assertCuota(array $esperada, array $real): void
    {
        $this->assertSame($esperada['numero_cuota'], $real['numero_cuota']);
        $this->assertSame($esperada['fecha_vencimiento'], $real['fecha_vencimiento']);
        $this->assertSame($esperada['valor'], $real['valor']->centavos());
        $this->assertSame($esperada['valor_interes'], $real['valor_interes']->centavos());
        $this->assertSame($esperada['valor_capital'], $real['valor_capital']->centavos());
        $this->assertSame($esperada['saldo_capital'], $real['saldo_capital']->centavos());
    }

    /**
     * Sin interés, préstamo que no divide exacto entre el plazo: la última
     * cuota absorbe el centavo de redondeo (mismo comportamiento documentado
     * para el sistema francés, no exclusivo de créditos con interés).
     */
    public function test_ultima_cuota_absorbe_el_redondeo_sin_interes(): void
    {
        $resultado = AmortizacionCalculator::calcular(Dinero::desdePesos(1000), 0, 3, TipoPago::Mensual, '2026-01-01');

        $this->assertSame(33333, $resultado['valor_cuota']->centavos());
        $this->assertSame(100000, $resultado['valor_total']->centavos());

        $valores = array_map(fn (Dinero $v) => $v->centavos(), array_column($resultado['cuotas'], 'valor'));
        $this->assertSame([33333, 33333, 33334], $valores);

        $this->assertSame(0, $resultado['cuotas'][2]['saldo_capital']->centavos());
    }

    /**
     * Invariante estructural: la suma del capital de todas las cuotas debe
     * saldar exactamente el valor prestado, sin importar tasa/plazo/tipo de pago.
     * Con aritmética entera esto ya no es "verificado con delta" — es una
     * garantía matemática (resta pura de enteros en cada iteración).
     */
    public function test_la_suma_del_capital_de_las_cuotas_salda_el_prestamo(): void
    {
        $casos = [
            [1500000, 7, 6, TipoPago::Mensual],
            [850000, 2.5, 12, TipoPago::Quincenal],
            [50000, 0, 4, TipoPago::Mensual],
        ];

        foreach ($casos as [$valorPrestamo, $tasa, $plazo, $tipoPago]) {
            $prestamo = Dinero::desdePesos($valorPrestamo);
            $resultado = AmortizacionCalculator::calcular($prestamo, $tasa, $plazo, $tipoPago, '2026-01-01');

            $capitalTotal = Dinero::sumarVarios(...array_column($resultado['cuotas'], 'valor_capital'));

            $this->assertSame($prestamo->centavos(), $capitalTotal->centavos(), "Capital total no salda el préstamo para el caso [{$valorPrestamo}, {$tasa}%, {$plazo}]");
            $this->assertSame(0, end($resultado['cuotas'])['saldo_capital']->centavos());
            $this->assertCount($plazo, $resultado['cuotas']);
        }
    }

    public function test_mensual_desde_fin_de_mes_no_salta_al_mes_siguiente(): void
    {
        $resultado = AmortizacionCalculator::calcular(Dinero::desdePesos(300000), 0, 4, TipoPago::Mensual, '2026-01-31');

        $this->assertSame(['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'], array_column($resultado['cuotas'], 'fecha_vencimiento'));
    }

    /** El primer vencimiento solo mueve las fechas: el primer mes cobra su interés completo. */
    public function test_el_primer_vencimiento_cambia_las_fechas_pero_no_el_interes(): void
    {
        $normal = AmortizacionCalculator::calcular(Dinero::desdePesos(1000), 10, 3, TipoPago::Mensual, '2026-01-01');
        $corrido = AmortizacionCalculator::calcular(Dinero::desdePesos(1000), 10, 3, TipoPago::Mensual, '2026-01-01', '2026-01-16');

        $this->assertSame(['2026-01-16', '2026-02-16', '2026-03-16'], array_column($corrido['cuotas'], 'fecha_vencimiento'));
        $this->assertSame(10000, $corrido['cuotas'][0]['valor_interes']->centavos());
        $this->assertSame($normal['valor_total']->centavos(), $corrido['valor_total']->centavos());
    }

    /** La primera quincena puede vencer a 11 o a 19 días del desembolso: el primer mes siempre cobra su interés completo. */
    public function test_quincenal_cobra_el_primer_mes_completo_sin_importar_los_dias_del_desembolso(): void
    {
        $prestamo = Dinero::desdePesos(300000);

        foreach (['2026-01-01', '2026-01-12', '2026-01-20'] as $desembolso) {
            $resultado = AmortizacionCalculator::calcular($prestamo, 3, 4, TipoPago::Quincenal, $desembolso);

            $this->assertSame($prestamo->multiplicarPorTasa(0.03)->centavos(), $this->interesDelPrimerMes($resultado), "Desembolso {$desembolso}");
        }
    }

    public function test_quincenal_con_primer_vencimiento_sigue_el_15_y_el_fin_de_mes_desde_esa_fecha(): void
    {
        $resultado = AmortizacionCalculator::calcular(Dinero::desdePesos(300000), 3, 4, TipoPago::Quincenal, '2026-01-01', '2026-01-31');

        $this->assertSame(['2026-01-31', '2026-02-15', '2026-02-28', '2026-03-15'], array_column($resultado['cuotas'], 'fecha_vencimiento'));
        $this->assertSame(Dinero::desdePesos(300000)->multiplicarPorTasa(0.03)->centavos(), $this->interesDelPrimerMes($resultado));
    }

    private function interesDelPrimerMes(array $resultado): int
    {
        return $resultado['cuotas'][0]['valor_interes']->sumar($resultado['cuotas'][1]['valor_interes'])->centavos();
    }

    /** 28,59 % EA equivale a 2,117600086… % mensual, al último decimal. */
    public function test_la_tasa_mensual_sale_de_la_efectiva_anual_como_en_la_tabla_de_dda(): void
    {
        $this->assertEqualsWithDelta(2.117600086268867, AmortizacionCalculator::tasaMensualDesdeEfectivaAnual(28.59), 1e-12);
        $this->assertSame(0.0, AmortizacionCalculator::tasaMensualDesdeEfectivaAnual(0));
    }

    /**
     * Caso de referencia de DDA: 22.000.000 al 28,59 % EA, 24 meses
     * cobrados en 48 quincenas. Cuota mensual 1.178.717,24; cada quincena es su mitad, 589.358,62.
     */
    public function test_quincenal_reproduce_la_tabla_de_amortizacion_de_dda(): void
    {
        $prestamo = Dinero::desdePesos(22000000);
        $tasa = AmortizacionCalculator::tasaMensualDesdeEfectivaAnual(28.59);
        $resultado = AmortizacionCalculator::calcular($prestamo, $tasa, 48, TipoPago::Quincenal, '2026-11-20');
        $cuotas = $resultado['cuotas'];

        $this->assertSame(58935862, $resultado['valor_cuota']->centavos());
        $this->assertCount(48, $cuotas);

        $this->assertSame(23293601, $cuotas[0]['valor_interes']->centavos());
        $this->assertSame(35642261, $cuotas[0]['valor_capital']->centavos());
        $this->assertSame(58935862, $cuotas[0]['valor']->centavos());
        $this->assertSame(58935862, $cuotas[1]['valor']->centavos());

        foreach (array_slice($cuotas, 0, 46) as $cuota) {
            $this->assertSame(58935862, $cuota['valor']->centavos(), "Cuota {$cuota['numero_cuota']}");
        }

        $this->assertSame($prestamo->centavos(), Dinero::sumarVarios(...array_column($cuotas, 'valor_capital'))->centavos());
        $this->assertSame(0, end($cuotas)['saldo_capital']->centavos());
    }

    /** Cada par de cuotas quincenales es exactamente una cuota del crédito mensual equivalente: mismo interés total. */
    public function test_cada_par_quincenal_es_la_cuota_del_mensual_equivalente(): void
    {
        $prestamo = Dinero::desdePesos(1000000);

        $mensual = AmortizacionCalculator::calcular($prestamo, 2, 6, TipoPago::Mensual, '2026-01-01');
        $quincenal = AmortizacionCalculator::calcular($prestamo, 2, 12, TipoPago::Quincenal, '2026-01-01');

        foreach ($mensual['cuotas'] as $mes => $cuotaMensual) {
            [$primera, $segunda] = array_slice($quincenal['cuotas'], $mes * 2, 2);

            foreach (['valor', 'valor_interes', 'valor_capital'] as $campo) {
                $this->assertSame($cuotaMensual[$campo]->centavos(), $primera[$campo]->sumar($segunda[$campo])->centavos(), "{$campo} del mes {$mes}");
            }
        }

        $this->assertSame($mensual['valor_total']->centavos(), $quincenal['valor_total']->centavos());
    }

    /** Reprogramación o abono a capital pueden dejar un número impar de quincenas: el último grupo es medio mes. */
    public function test_quincenal_con_plazo_impar_cobra_medio_mes_al_final_y_salda_el_prestamo(): void
    {
        $prestamo = Dinero::desdePesos(300000);
        $resultado = AmortizacionCalculator::calcular($prestamo, 3, 5, TipoPago::Quincenal, '2026-01-01');
        $ultima = end($resultado['cuotas']);

        $this->assertCount(5, $resultado['cuotas']);
        $this->assertSame(0, $ultima['saldo_capital']->centavos());
        $this->assertSame($resultado['cuotas'][3]['saldo_capital']->multiplicarPorTasa(0.03 / 2)->centavos(), $ultima['valor_interes']->centavos());
        $this->assertSame($prestamo->centavos(), Dinero::sumarVarios(...array_column($resultado['cuotas'], 'valor_capital'))->centavos());
    }

    public function test_quincenal_vence_el_15_y_el_ultimo_dia_de_cada_mes(): void
    {
        $resultado = AmortizacionCalculator::calcular(Dinero::desdePesos(300000), 0, 5, TipoPago::Quincenal, '2026-01-01');

        $this->assertSame(['2026-01-15', '2026-01-31', '2026-02-15', '2026-02-28', '2026-03-15'], array_column($resultado['cuotas'], 'fecha_vencimiento'));
    }

    /** Desembolso el 12: el 15 queda a 3 días, así que la primera cuota pasa al 31. */
    public function test_quincenal_no_cobra_la_primera_cuota_a_pocos_dias_del_desembolso(): void
    {
        $resultado = AmortizacionCalculator::calcular(Dinero::desdePesos(300000), 3, 3, TipoPago::Quincenal, '2026-01-12');

        $this->assertSame(['2026-01-31', '2026-02-15', '2026-02-28'], array_column($resultado['cuotas'], 'fecha_vencimiento'));
        $this->assertSame(0, end($resultado['cuotas'])['saldo_capital']->centavos());
    }

    public function test_primer_vencimiento_quincenal_respeta_los_dias_minimos(): void
    {
        $primero = fn (string $desembolso) => AmortizacionCalculator::primerVencimientoQuincenal(CarbonImmutable::parse($desembolso))->toDateString();

        $this->assertSame('2026-01-15', $primero('2026-01-05'));
        $this->assertSame('2026-01-31', $primero('2026-01-06'));
        $this->assertSame('2026-01-31', $primero('2026-01-15'));
        $this->assertSame('2026-03-15', $primero('2026-02-27'));
        $this->assertSame('2026-03-15', $primero('2026-02-28'));
    }

    public function test_desembolso_un_15_o_fin_de_mes_vence_en_el_siguiente_dia_fijo(): void
    {
        $this->assertSame('2026-02-28', AmortizacionCalculator::siguienteDiaQuincenal(CarbonImmutable::parse('2026-02-15'))->toDateString());
        $this->assertSame('2026-03-15', AmortizacionCalculator::siguienteDiaQuincenal(CarbonImmutable::parse('2026-02-28'))->toDateString());
        $this->assertSame('2024-02-29', AmortizacionCalculator::siguienteDiaQuincenal(CarbonImmutable::parse('2024-02-16'))->toDateString());
    }

    public function test_quincenal_rechaza_un_primer_vencimiento_que_no_es_dia_fijo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AmortizacionCalculator::calcular(Dinero::desdePesos(300000), 3, 3, TipoPago::Quincenal, '2026-01-01', '2026-01-10');
    }
}
