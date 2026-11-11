<?php

namespace App\Creditos\Commands;

use App\Creditos\Services\AlineacionQuincenalService;
use App\Empresas\Models\Empresa;
use Illuminate\Console\Command;

/** Por defecto solo muestra lo que cambiaría: aplica únicamente con `--aplicar`. La empresa es obligatoria. */
class AlinearCuotasQuincenalesCommand extends Command
{
    protected $signature = 'creditos:alinear-quincenas
        {--empresa= : ID de la empresa (obligatorio)}
        {--aplicar : Guarda los cambios; sin esta opción solo se listan}';

    protected $description = 'Mueve las cuotas quincenales sin pagos de los créditos desembolsados al 15 y al último día del mes';

    public function handle(AlineacionQuincenalService $alineacion): int
    {
        $empresaId = filter_var($this->option('empresa'), FILTER_VALIDATE_INT);

        if ($empresaId === false || ! Empresa::query()->whereKey($empresaId)->exists()) {
            $this->error('Indica una empresa existente con --empresa=<id>.');

            return self::FAILURE;
        }

        $plan = $alineacion->planificar($empresaId);

        if ($plan === []) {
            $this->info('No hay cuotas quincenales por alinear.');

            return self::SUCCESS;
        }

        $filas = [];
        foreach ($plan as $credito) {
            foreach ($credito['cambios'] as $cambio) {
                $filas[] = [$credito['credito']->id, $credito['credito']->codigo, $cambio['numero_cuota'], $cambio['antes'], $cambio['despues']];
            }
        }
        $this->table(['Crédito', 'Código', 'Cuota', 'Vence hoy', 'Vencerá'], $filas);

        if (! $this->option('aplicar')) {
            $this->warn(count($plan).' crédito(s) y '.count($filas).' cuota(s) cambiarían. Es una simulación: no se guardó nada. Usa --aplicar para guardar.');

            return self::SUCCESS;
        }

        $alineados = $alineacion->aplicar($empresaId);
        $this->info("{$alineados} crédito(s) alineados. Quedó registro en la auditoría.");

        return self::SUCCESS;
    }
}
