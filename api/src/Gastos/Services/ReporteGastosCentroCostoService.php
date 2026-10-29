<?php

namespace App\Gastos\Services;

use App\Core\Dinero\Dinero;
use App\Exportacion\Enums\FormatoExportacion;
use App\Exportacion\Models\Exportacion;
use App\Exportacion\Services\ExportacionService;
use App\Gastos\Exportaciones\FuenteGastosCentroCosto;
use App\Gastos\Repositories\GastoRepository;
use App\Usuarios\Models\Usuario;
use Illuminate\Support\Carbon;

/**
 * Gastos causados por mes y centro de costo. Recibe meses (YYYY-MM) y los convierte al rango de fechas completo.
 * Los gastos anulados no entran; un gasto sin centro (anterior a la obligatoriedad) aparece con centro nulo.
 */
class ReporteGastosCentroCostoService
{
    public function __construct(
        protected GastoRepository $gastos,
        protected ExportacionService $exportaciones,
    ) {}

    /** @return array{filas: list<array<string, mixed>>, total_cantidad: int, total_bruto: Dinero, total_neto: Dinero} */
    public function resumen(int $empresaId, string $desdeMes, string $hastaMes): array
    {
        [$desde, $hasta] = $this->rango($desdeMes, $hastaMes);

        $filas = $this->gastos->resumenPorCentroCosto($empresaId, $desde, $hasta)->map(fn ($fila) => [
            'mes' => $fila->mes,
            'centro_costo' => $fila->centro_costo_id ? [
                'id' => (int) $fila->centro_costo_id,
                'codigo' => $fila->centro_codigo,
                'nombre' => $fila->centro_nombre,
            ] : null,
            'cantidad' => (int) $fila->cantidad,
            'valor_bruto' => Dinero::desdeCentavos((int) $fila->valor_bruto),
            'valor_neto' => Dinero::desdeCentavos((int) $fila->valor_neto),
        ])->values();

        return [
            'filas' => $filas->all(),
            'total_cantidad' => $filas->sum('cantidad'),
            'total_bruto' => Dinero::sumarVarios(...$filas->pluck('valor_bruto')->all()),
            'total_neto' => Dinero::sumarVarios(...$filas->pluck('valor_neto')->all()),
        ];
    }

    public function solicitarExportacion(Usuario $usuario, string $desdeMes, string $hastaMes, FormatoExportacion $formato): Exportacion
    {
        return $this->exportaciones->solicitar(new FuenteGastosCentroCosto((int) $usuario->empresa_id, $desdeMes, $hastaMes), $formato, $usuario);
    }

    /** @return array{0: string, 1: string} */
    public function rango(string $desdeMes, string $hastaMes): array
    {
        return [
            Carbon::createFromFormat('Y-m-d', $desdeMes.'-01')->startOfMonth()->toDateString(),
            Carbon::createFromFormat('Y-m-d', $hastaMes.'-01')->endOfMonth()->toDateString(),
        ];
    }
}
