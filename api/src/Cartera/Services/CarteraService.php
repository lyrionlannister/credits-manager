<?php

namespace App\Cartera\Services;

use App\Cartera\Constants\CarteraConstants;
use App\Cartera\Exportaciones\FuenteCartera;
use App\Core\Dinero\Dinero;
use App\Creditos\Repositories\CreditoRepository;
use App\Creditos\Repositories\CuotaRepository;
use App\Creditos\Services\CuotaService;
use App\Empleadores\Repositories\EmpleadorRepository;
use App\Exportacion\Enums\FormatoExportacion;
use App\Exportacion\Models\Exportacion;
use App\Exportacion\Services\ExportacionService;
use App\Usuarios\Models\Usuario;
use Illuminate\Support\Collection;

/**
 * Sin tabla propia — solo lectura sobre créditos/cuotas activos. Reusa
 * `CuotaService` (mismo dueño de la lógica de mora que Créditos/Calendario).
 */
class CarteraService
{
    public function __construct(
        protected CuotaService $cuotaService,
        protected CreditoRepository $creditos,
        protected EmpleadorRepository $empleadores,
        protected CuotaRepository $cuotas,
        protected ExportacionService $exportaciones,
    ) {}

    /** El total de cartera es siempre el del propio asesor (`idAsesorParaCartera`), aunque vea todos los clientes. */
    public function resumen(Usuario $usuario): array
    {
        $this->cuotaService->actualizarVencidas();

        $clasificados = $this->clasificar($this->creditos->activosDeEmpresa((int) $usuario->empresa_id, $usuario->idAsesorParaCartera()));
        $alDia = $clasificados['al_dia'];
        $enMora = $clasificados['en_mora'];

        return [
            'al_dia' => $alDia,
            'en_mora' => $enMora,
            'totales' => [
                'al_dia' => $this->totalConPromedio($alDia),
                'en_mora' => $this->totalConPromedio($enMora),
            ],
            'envejecimiento_mora' => $this->envejecimientoMora($enMora),
        ];
    }

    public function solicitarExportacion(Usuario $usuario, FormatoExportacion $formato): Exportacion
    {
        $this->cuotaService->actualizarVencidas();

        return $this->exportaciones->solicitar(
            new FuenteCartera((int) $usuario->empresa_id, $usuario->idAsesorConAlcance()),
            $formato,
            $usuario,
        );
    }

    /**
     * Filas de la cartera de la empresa (o del asesor), con la misma clasificación que la pantalla.
     * Se llama desde el worker: la empresa y el asesor llegan explícitos, sin sesión.
     *
     * @return list<array{credito: int, cliente: string, estado: string, dias_mora: ?int, saldo: Dinero}>
     */
    public function filasParaExportar(int $empresaId, ?int $asesorId): array
    {
        $clasificados = $this->clasificar($this->creditos->activosDeEmpresa($empresaId, $asesorId));

        $filas = [];

        foreach ($clasificados['al_dia'] as $fila) {
            $filas[] = $this->filaExportable($fila, 'Al día', null);
        }

        foreach ($clasificados['en_mora'] as $fila) {
            $filas[] = $this->filaExportable($fila, 'En mora', $fila['dias_mora']);
        }

        usort($filas, fn (array $a, array $b) => strcmp($a['cliente'], $b['cliente']));

        return $filas;
    }

    /** @return array{al_dia: list<array>, en_mora: list<array>} */
    private function clasificar(Collection $creditos): array
    {
        $resumenPorCredito = $this->cuotas->resumenPorCreditos($creditos->pluck('id')->all());

        $alDia = [];
        $enMora = [];

        foreach ($creditos as $credito) {
            $fila = $resumenPorCredito[$credito->id] ?? ['saldo' => 0, 'vencidas' => 0, 'primera_fecha_vencida' => null];
            $saldo = Dinero::desdeCentavos($fila['saldo']);

            if ((int) $fila['vencidas'] > 0) {
                $diasMora = $this->cuotaService->diasMoraDesdeFecha($fila['primera_fecha_vencida']);
                $enMora[] = ['credito' => $credito, 'saldo' => $saldo, 'dias_mora' => $diasMora];
            } else {
                $alDia[] = ['credito' => $credito, 'saldo' => $saldo];
            }
        }

        return ['al_dia' => $alDia, 'en_mora' => $enMora];
    }

    /** @return array{credito: int, cliente: string, estado: string, dias_mora: ?int, saldo: Dinero} */
    private function filaExportable(array $fila, string $estado, ?int $diasMora): array
    {
        return [
            'credito' => $fila['credito']->id,
            'cliente' => $fila['credito']->cliente->tercero->nombre,
            'estado' => $estado,
            'dias_mora' => $diasMora,
            'saldo' => $fila['saldo'],
        ];
    }

    private function totalConPromedio(array $filas): array
    {
        $count = count($filas);
        $saldo = Dinero::sumarVarios(...array_column($filas, 'saldo'));

        return [
            'count' => $count,
            'saldo' => $saldo,
            'promedio' => $count > 0 ? $saldo->dividirEntre($count) : Dinero::cero(),
        ];
    }

    /**
     * Agrupa los créditos en mora en los rangos de `CarteraConstants::RANGOS_MORA`
     * (1-30 / 31-60 / 61-90 / más de 90 días), a partir del `dias_mora` que ya calcula `resumen()`.
     */
    private function envejecimientoMora(array $enMora): array
    {
        $rangos = array_map(fn () => ['count' => 0, 'saldo' => Dinero::cero()], CarteraConstants::RANGOS_MORA);

        foreach ($enMora as $fila) {
            $clave = $this->rangoDeMora($fila['dias_mora']);

            $rangos[$clave]['count']++;
            $rangos[$clave]['saldo'] = $rangos[$clave]['saldo']->sumar($fila['saldo']);
        }

        return $rangos;
    }

    private function rangoDeMora(int $diasMora): string
    {
        foreach (CarteraConstants::RANGOS_MORA as $clave => $hasta) {
            if ($hasta === null || $diasMora <= $hasta) {
                return $clave;
            }
        }

        return array_key_last(CarteraConstants::RANGOS_MORA);
    }

    public function reporteEmpleador(?int $empleadorId): array
    {
        $this->cuotaService->actualizarVencidas();

        $empleadores = $this->empleadores->conClientes();
        $empleadorSeleccionado = $empleadorId ?? $empleadores->first()?->id;

        $meses = [];
        $filas = [];

        if ($empleadorSeleccionado) {
            foreach ($this->cuotas->porEmpleador($empleadorSeleccionado) as $cuota) {
                $periodo = $cuota->fecha_vencimiento->format('Y-m');

                if (! in_array($periodo, $meses, true)) {
                    $meses[] = $periodo;
                }

                $clienteId = $cuota->credito->cliente_id;
                $filas[$clienteId] ??= [
                    'cliente_id' => $clienteId,
                    'cliente_nombre' => $cuota->credito->cliente->tercero->nombre,
                    'valor_cuota' => $cuota->credito->valor_cuota_total,
                    'cuotas' => [],
                ];
                $filas[$clienteId]['cuotas'][$periodo] = ['valor' => $cuota->valor_total_cuota, 'estado' => $cuota->estado];
            }

            sort($meses);
        }

        $totalesPorMes = array_fill_keys($meses, Dinero::cero());
        foreach ($filas as $fila) {
            foreach ($fila['cuotas'] as $periodo => $cuota) {
                $totalesPorMes[$periodo] = $totalesPorMes[$periodo]->sumar($cuota['valor']);
            }
        }

        return [
            'empleadores' => $empleadores,
            'empleador_seleccionado' => $empleadorSeleccionado,
            'meses' => $meses,
            'filas' => array_values($filas),
            'totales_por_mes' => $totalesPorMes,
        ];
    }
}
