<?php

namespace App\Dashboard\Repositories;

use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Enums\EstadoCuota;
use App\Gastos\Enums\EstadoGasto;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Agregados del dashboard, todos en SQL y en centavos. Usa `DB::table` sin scopes de Eloquent porque el superadmin
 * no tiene empresa de sesión: cada método recibe `empresa_id` explícito y lo repite en cada tabla unida. El alcance
 * del asesor sigue el criterio de `AlcanceAsesorScope` (clientes.asesor_id) y se aplica en `filtrarCreditos`.
 * Es la única lectura del dashboard que se salta los scopes; no hay otra en el módulo.
 */
class DashboardRepository
{
    /** Créditos que ya generaron colocación. Un solicitado, aprobado o cancelado no cuenta. */
    private const ESTADOS_COLOCADOS = [EstadoCredito::Desembolsado, EstadoCredito::Pagado, EstadoCredito::Refinanciado];

    /** @return array<string, array{valor: int, cantidad: int}> Centavos y conteo indexados por mes `YYYY-MM`. */
    public function colocacionPorMes(int $empresaId, ?int $asesorId, ?int $clienteId, ?EstadoCredito $estado, string $desde, string $hasta): array
    {
        $consulta = $this->filtrarCreditos(
            $this->creditosDeEmpresa($empresaId)
                ->where('creditos.fecha_desembolso', '>=', $desde)
                ->where('creditos.fecha_desembolso', '<', $hasta),
            self::ESTADOS_COLOCADOS,
            $asesorId,
            $clienteId,
            $estado,
        );

        return $consulta
            ->selectRaw($this->expresionMes('creditos.fecha_desembolso').' AS mes, COALESCE(SUM(creditos.valor_prestamo), 0) AS valor, COUNT(*) AS cantidad')
            ->groupBy('mes')
            ->get()
            ->mapWithKeys(fn ($fila) => [$fila->mes => ['valor' => (int) $fila->valor, 'cantidad' => (int) $fila->cantidad]])
            ->all();
    }

    /** @return array<string, int> Centavos recaudados por mes `YYYY-MM`. */
    public function recaudoPorMes(int $empresaId, ?int $asesorId, ?int $clienteId, ?EstadoCredito $estado, string $desde, string $hasta): array
    {
        $consulta = DB::table('pagos')
            ->join('creditos', 'creditos.id', '=', 'pagos.credito_id')
            ->join('clientes', 'clientes.id', '=', 'creditos.cliente_id')
            ->where('pagos.empresa_id', $empresaId)
            ->where('creditos.empresa_id', $empresaId)
            ->where('clientes.empresa_id', $empresaId)
            ->whereNull('pagos.borrado_en')
            ->where('pagos.fecha_pago', '>=', $desde)
            ->where('pagos.fecha_pago', '<', $hasta)
            // Misma regla que ComportamientoMensualRepository: una reversa del mismo mes o anterior saca el pago; una posterior no lo borra de su mes.
            ->whereNotExists(fn ($reversa) => $reversa->selectRaw('1')
                ->from('reversas_pago')
                ->whereColumn('reversas_pago.pago_id', 'pagos.id')
                ->where('reversas_pago.empresa_id', $empresaId)
                ->whereRaw('SUBSTR(reversas_pago.fecha, 1, 7) <= SUBSTR(pagos.fecha_pago, 1, 7)'));

        return $this->filtrarCreditos($consulta, EstadoCredito::cases(), $asesorId, $clienteId, $estado)
            ->selectRaw($this->expresionMes('pagos.fecha_pago').' AS mes, COALESCE(SUM(pagos.valor_pago), 0) AS valor')
            ->groupBy('mes')
            ->get()
            ->mapWithKeys(fn ($fila) => [$fila->mes => (int) $fila->valor])
            ->all();
    }

    /** Gasto causado (no anulado) por mes, en centavos. Los gastos son de la empresa: no dependen de asesor ni de cliente. */
    public function gastosPorMes(int $empresaId, string $desde, string $hasta): array
    {
        return DB::table('gastos')
            ->where('empresa_id', $empresaId)
            ->where('estado', EstadoGasto::Causado->value)
            ->whereNull('borrado_en')
            ->where('fecha', '>=', $desde)
            ->where('fecha', '<', $hasta)
            ->selectRaw($this->expresionMes('fecha').' AS mes, COALESCE(SUM(valor_bruto), 0) AS valor')
            ->groupBy('mes')
            ->get()
            ->mapWithKeys(fn ($fila) => [$fila->mes => (int) $fila->valor])
            ->all();
    }

    /**
     * Cartera de créditos desembolsados a la fecha de hoy, en centavos. Vencida = marcada `vencida` o con
     * vencimiento anterior a hoy, para no depender de que la mora ya se haya persistido.
     *
     * @return array{pendiente: int, al_dia: int, vencida: int}
     */
    public function carteraActual(int $empresaId, ?int $asesorId, ?int $clienteId, ?EstadoCredito $estado, string $hoy): array
    {
        $saldo = 'cuotas.valor + cuotas.valor_otros_gastos - cuotas.valor_pagado';
        $esVencida = "(cuotas.estado = '".EstadoCuota::Vencida->value."' OR cuotas.fecha_vencimiento < ?)";

        $fila = $this->filtrarCreditos(
            $this->cuotasPendientes($empresaId),
            [EstadoCredito::Desembolsado],
            $asesorId,
            $clienteId,
            $estado,
        )->selectRaw(
            "COALESCE(SUM({$saldo}), 0) AS pendiente,
             COALESCE(SUM(CASE WHEN {$esVencida} THEN 0 ELSE {$saldo} END), 0) AS al_dia,
             COALESCE(SUM(CASE WHEN {$esVencida} THEN {$saldo} ELSE 0 END), 0) AS vencida",
            [$hoy, $hoy],
        )->first();

        return [
            'pendiente' => (int) $fila->pendiente,
            'al_dia' => (int) $fila->al_dia,
            'vencida' => (int) $fila->vencida,
        ];
    }

    /**
     * Créditos en mora agrupados por rango de días de mora, en SQL. Cada crédito cuenta por su vencimiento más
     * antiguo; `$limites` trae la fecha mínima de vencimiento de cada rango (1-30, 31-60, 61-90) y el resto es más de 90.
     *
     * @param  array{'1_30': string, '31_60': string, '61_90': string}  $limites
     * @return array<string, array{creditos: int, saldo: int}>
     */
    public function moraPorRango(int $empresaId, ?int $asesorId, ?int $clienteId, ?EstadoCredito $estado, string $hoy, array $limites): array
    {
        $esVencida = "(cuotas.estado = '".EstadoCuota::Vencida->value."' OR cuotas.fecha_vencimiento < ?)";

        $porCredito = $this->filtrarCreditos(
            $this->cuotasPendientes($empresaId),
            [EstadoCredito::Desembolsado],
            $asesorId,
            $clienteId,
            $estado,
        )->selectRaw("cuotas.credito_id, SUM(cuotas.valor + cuotas.valor_otros_gastos - cuotas.valor_pagado) AS saldo, MIN(CASE WHEN {$esVencida} THEN cuotas.fecha_vencimiento END) AS primera", [$hoy])
            ->groupBy('cuotas.credito_id')
            ->havingRaw("MIN(CASE WHEN {$esVencida} THEN cuotas.fecha_vencimiento END) IS NOT NULL", [$hoy]);

        return DB::query()
            ->fromSub($porCredito, 'mora')
            ->selectRaw(
                "CASE WHEN primera >= ? THEN '1_30' WHEN primera >= ? THEN '31_60' WHEN primera >= ? THEN '61_90' ELSE 'mas_90' END AS rango, COUNT(*) AS creditos, COALESCE(SUM(saldo), 0) AS saldo",
                [$limites['1_30'], $limites['31_60'], $limites['61_90']],
            )
            ->groupBy('rango')
            ->get()
            ->mapWithKeys(fn ($fila) => [$fila->rango => ['creditos' => (int) $fila->creditos, 'saldo' => (int) $fila->saldo]])
            ->all();
    }

    /** Cuotas que vencen hoy de créditos desembolsados, con el nombre del cliente para la tabla del día. */
    public function pagosDelDia(int $empresaId, ?int $asesorId, ?int $clienteId, ?EstadoCredito $estado, string $hoy): array
    {
        return $this->filtrarCreditos(
            $this->cuotasPendientes($empresaId)
                ->join('terceros', 'terceros.id', '=', 'clientes.tercero_id')
                ->where('terceros.empresa_id', $empresaId)
                ->where('cuotas.fecha_vencimiento', $hoy),
            [EstadoCredito::Desembolsado],
            $asesorId,
            $clienteId,
            $estado,
        )
            ->orderBy('terceros.nombre')
            ->orderBy('cuotas.numero_cuota')
            ->get([
                'cuotas.id as cuota_id',
                'cuotas.credito_id',
                'terceros.nombre as cliente_nombre',
                'cuotas.numero_cuota',
                'cuotas.valor',
                'cuotas.valor_otros_gastos',
                'cuotas.valor_pagado',
            ])
            ->all();
    }

    public function contarCreditosActivos(int $empresaId, ?int $asesorId, ?int $clienteId, ?EstadoCredito $estado): int
    {
        return $this->filtrarCreditos($this->creditosDeEmpresa($empresaId), [EstadoCredito::Desembolsado], $asesorId, $clienteId, $estado)->count();
    }

    public function contarClientesActivos(int $empresaId, ?int $asesorId): int
    {
        return DB::table('clientes')
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->whereNull('borrado_en')
            ->when($asesorId !== null, fn (Builder $consulta) => $consulta->where('asesor_id', $asesorId))
            ->count();
    }

    private function creditosDeEmpresa(int $empresaId): Builder
    {
        return DB::table('creditos')
            ->join('clientes', 'clientes.id', '=', 'creditos.cliente_id')
            ->where('creditos.empresa_id', $empresaId)
            ->where('clientes.empresa_id', $empresaId)
            ->whereNull('creditos.borrado_en');
    }

    private function cuotasPendientes(int $empresaId): Builder
    {
        return $this->creditosDeEmpresa($empresaId)
            ->join('cuotas', 'cuotas.credito_id', '=', 'creditos.id')
            ->whereNull('cuotas.borrado_en')
            ->whereIn('cuotas.estado', [EstadoCuota::Pendiente->value, EstadoCuota::Vencida->value]);
    }

    /**
     * Aplica la intersección entre los estados permitidos para la métrica y el filtro elegido, más el alcance
     * del asesor y el cliente. Sobre `creditos` y `clientes` ya unidos.
     *
     * @param  EstadoCredito[]  $estadosPermitidos
     */
    private function filtrarCreditos(Builder $consulta, array $estadosPermitidos, ?int $asesorId, ?int $clienteId, ?EstadoCredito $estado): Builder
    {
        $estados = $estado === null ? $estadosPermitidos : (in_array($estado, $estadosPermitidos, true) ? [$estado] : []);
        $consulta->whereIn('creditos.estado', array_map(fn (EstadoCredito $valor) => $valor->value, $estados));

        if ($asesorId !== null) {
            $consulta->where('clientes.asesor_id', $asesorId);
        }

        if ($clienteId !== null) {
            $consulta->where('creditos.cliente_id', $clienteId);
        }

        return $consulta;
    }

    /** Agrupa por mes sin depender de una función de un solo motor: MySQL en producción, SQLite en tests. */
    private function expresionMes(string $columna): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$columna})"
            : "DATE_FORMAT({$columna}, '%Y-%m')";
    }
}
