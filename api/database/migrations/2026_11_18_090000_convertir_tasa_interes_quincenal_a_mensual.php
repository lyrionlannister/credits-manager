<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `AmortizacionCalculator::calcular()` ahora interpreta `tasa_interes` siempre como tasa mensual y deriva la tasa
 * quincenal con `AmortizacionCalculator::tasaPeriodo()`. Antes, para créditos Quincenal, `tasa_interes` se usaba
 * tal cual como tasa del periodo (quincenal), sin convertir — así fue como se generó el plan de cuotas ya guardado
 * de cada crédito existente.
 *
 * Reprogramación, abono a capital y la liquidación anticipada (`PagoService`) siguen leyendo `tasa_interes` del
 * crédito para recalcular. Sin este backfill, esa lectura pasaría por la nueva conversión mensual→quincenal y
 * produciría una tasa periódica distinta a la que ya está implícita en las cuotas guardadas. El backfill reemplaza
 * `tasa_interes` por la tasa mensual equivalente, de forma que `tasaPeriodo()` reproduzca la misma tasa quincenal
 * de siempre: no cambia nada de lo ya cobrado ni de lo ya cotizado, solo re-expresa el dato guardado en la nueva
 * convención. Los créditos Mensual no se tocan (la convención no cambió para ellos).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->convertir(
            fn (float $tasaQuincenal) => ((1 + $tasaQuincenal / 100) ** 2 - 1) * 100,
        );
    }

    public function down(): void
    {
        $this->convertir(
            fn (float $tasaMensual) => ((1 + $tasaMensual / 100) ** 0.5 - 1) * 100,
        );
    }

    private function convertir(callable $convertirTasa): void
    {
        DB::table('creditos')
            ->where('tipo_pago', 'quincenal')
            ->orderBy('id')
            ->chunkById(200, function ($creditos) use ($convertirTasa) {
                foreach ($creditos as $credito) {
                    DB::table('creditos')->where('id', $credito->id)->update([
                        'tasa_interes' => round($convertirTasa((float) $credito->tasa_interes), 3),
                    ]);
                }
            });
    }
};
