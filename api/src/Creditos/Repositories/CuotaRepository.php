<?php

namespace App\Creditos\Repositories;

use App\Core\Base\BaseRepository;
use App\Core\Dinero\Dinero;
use App\Core\Scopes\AlcanceAsesorScope;
use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Models\Cuota;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class CuotaRepository extends BaseRepository
{
    public function __construct(Cuota $cuota)
    {
        parent::__construct($cuota);
    }

    public function pendientesOrdenadasParaActualizar(int $creditoId): Collection
    {
        return $this->consulta()
            ->where('credito_id', $creditoId)
            ->whereIn('estado', [EstadoCuota::Pendiente, EstadoCuota::Vencida])
            ->orderBy('numero_cuota')
            ->lockForUpdate()
            ->get();
    }

    /** Lectura simple (sin lock): conceptos sugeridos de factura, no una actualización de estado. */
    public function pendientesOrdenadas(int $creditoId): Collection
    {
        return $this->consulta()
            ->where('credito_id', $creditoId)
            ->whereIn('estado', [EstadoCuota::Pendiente, EstadoCuota::Vencida])
            ->orderBy('numero_cuota')
            ->get();
    }

    /** Lectura simple (sin lock): conceptos sugeridos de "facturar intereses pagados". */
    public function pagadasOrdenadas(int $creditoId): Collection
    {
        return $this->consulta()
            ->where('credito_id', $creditoId)
            ->where('estado', EstadoCuota::Pagada)
            ->orderBy('numero_cuota')
            ->get();
    }

    public function todasOrdenadasParaActualizar(int $creditoId): Collection
    {
        return $this->consulta()
            ->where('credito_id', $creditoId)
            ->orderBy('numero_cuota')
            ->lockForUpdate()
            ->get();
    }

    /** Incluye las que una reprogramación dejó en `borrado_en`: la foto del plan anterior las puede devolver. */
    public function deCreditoConEliminadas(int $creditoId, array $ids): Collection
    {
        return $this->consulta()
            ->withTrashed()
            ->where('credito_id', $creditoId)
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get();
    }

    public function eliminarDeCreditoExcepto(int $creditoId, array $idsQueSeQuedan): void
    {
        $this->consulta()->where('credito_id', $creditoId)->whereNotIn('id', $idsQueSeQuedan)->delete();
    }

    public function eliminarTodasDeCredito(int $creditoId): void
    {
        $this->consulta()->where('credito_id', $creditoId)->delete();
    }

    public function todasPorCredito(int $creditoId): Collection
    {
        return $this->consulta()
            ->where('credito_id', $creditoId)
            ->orderBy('numero_cuota')
            ->get();
    }

    public function contarNoPagadas(int $creditoId): int
    {
        return $this->consulta()
            ->where('credito_id', $creditoId)
            ->where('estado', '!=', EstadoCuota::Pagada)
            ->count();
    }

    public function crearVarias(int $creditoId, array $filas): void
    {
        $ahora = now();

        Cuota::insert(array_map(fn (array $fila) => [
            ...$fila,
            'valor' => $fila['valor']->centavos(),
            'valor_interes' => $fila['valor_interes']->centavos(),
            // Sin otros gastos en la fila (p. ej. importación histórica), la cuota queda en cero.
            'valor_otros_gastos' => ($fila['valor_otros_gastos'] ?? Dinero::cero())->centavos(),
            'valor_capital' => $fila['valor_capital']->centavos(),
            'saldo_capital' => $fila['saldo_capital']->centavos(),
            'credito_id' => $creditoId,
            'estado' => EstadoCuota::Pendiente->value,
            'creado_en' => $ahora,
            'ultima_actualizacion' => $ahora,
        ], $filas));
    }

    /**
     * `cuotas` no tiene `empresa_id` propio (es línea de detalle de `creditos`, igual que
     * `asientos_contables` respecto a `comprobantes`): el aislamiento por empresa se hace
     * vía `whereHas('credito')`, que hereda el `EmpresaScope` de `Credito`. Sin este filtro,
     * esta actualización corría sobre las cuotas de TODAS las empresas del sistema.
     * Solo entran en mora los créditos desembolsados: un solicitado, cancelado o refinanciado no cobra.
     * Excepción declarada al alcance por asesor: la mora es un proceso de toda la empresa, así que no se
     * limita a los clientes del asesor que dispara la lectura.
     */
    public function marcarVencidas(): Collection
    {
        return DB::transaction(function () {
            // lockForUpdate: dos peticiones simultáneas no marcan (ni reportan) las mismas cuotas dos veces.
            $cuotas = $this->consulta()
                ->where('estado', EstadoCuota::Pendiente)
                ->where('fecha_vencimiento', '<', now()->toDateString())
                ->whereHas('credito', fn ($consultaCredito) => $consultaCredito->withoutGlobalScope(AlcanceAsesorScope::class)->where('estado', EstadoCredito::Desembolsado))
                ->with('credito:id,empresa_id')
                ->lockForUpdate()
                ->get(['id', 'credito_id']);

            foreach (array_chunk($cuotas->modelKeys(), 1000) as $lote) {
                $this->consulta()->whereIn('id', $lote)->update(['estado' => EstadoCuota::Vencida]);
            }

            return $cuotas;
        });
    }

    /** Centavos enteros — `SUM()` de MySQL sobre columnas `bigint` es exacto. */
    public function saldoPendiente(int $creditoId): int
    {
        $saldo = $this->consulta()
            ->where('credito_id', $creditoId)
            ->select(DB::raw('SUM(valor + valor_otros_gastos - valor_pagado) as saldo'))
            ->value('saldo');

        return (int) ($saldo ?? 0);
    }

    /** Cuotas del cliente que aún se pueden cobrar. El crédito aplica el scope de empresa y de asesor. */
    public function cobrablesDelCliente(int $clienteId, int $limite = 50): Collection
    {
        return $this->consulta()
            ->with('credito:id,codigo,cliente_id')
            ->whereIn('estado', [EstadoCuota::Pendiente, EstadoCuota::Vencida])
            ->whereHas('credito', fn ($consulta) => $consulta->where('cliente_id', $clienteId))
            ->orderBy('fecha_vencimiento')
            ->orderBy('id')
            ->limit($limite)
            ->get();
    }

    public function delClienteConCredito(int $cuotaId, int $clienteId): ?Cuota
    {
        return $this->consulta()
            ->with('credito:id,codigo,cliente_id,empresa_id')
            ->whereKey($cuotaId)
            ->whereHas('credito', fn ($consulta) => $consulta->where('cliente_id', $clienteId))
            ->first();
    }

    public function contarVencidas(int $creditoId): int
    {
        return $this->consulta()
            ->where('credito_id', $creditoId)
            ->where('estado', EstadoCuota::Vencida)
            ->count();
    }

    public function primeraFechaVencida(int $creditoId): ?string
    {
        return $this->consulta()
            ->where('credito_id', $creditoId)
            ->where('estado', EstadoCuota::Vencida)
            ->min('fecha_vencimiento');
    }

    /** @return array<int, array{saldo: int, vencidas: int, primera_fecha_vencida: ?string}> `saldo` en centavos enteros. */
    /** Plan de cuotas del crédito en orden, en streaming para exportar. */
    public function cursorDelCredito(int $creditoId): LazyCollection
    {
        return $this->consulta()->where('credito_id', $creditoId)->orderBy('numero_cuota')->lazy();
    }

    public function resumenPorCreditos(array $creditoIds): array
    {
        return $this->consulta()
            ->whereIn('credito_id', $creditoIds)
            ->selectRaw('
                credito_id,
                SUM(valor + valor_otros_gastos - valor_pagado) as saldo,
                SUM(CASE WHEN estado = ? THEN valor + valor_otros_gastos - valor_pagado ELSE 0 END) as saldo_mora,
                SUM(CASE WHEN estado = ? THEN 1 ELSE 0 END) as vencidas,
                MIN(CASE WHEN estado = ? THEN fecha_vencimiento END) as primera_fecha_vencida
            ', [EstadoCuota::Vencida->value, EstadoCuota::Vencida->value, EstadoCuota::Vencida->value])
            ->groupBy('credito_id')
            ->get()
            ->keyBy('credito_id')
            ->map(fn ($fila) => [
                'saldo' => (int) $fila->saldo,
                'saldo_mora' => (int) $fila->saldo_mora,
                'vencidas' => (int) $fila->vencidas,
                'primera_fecha_vencida' => $fila->primera_fecha_vencida,
            ])
            ->all();
    }

    /**
     * Todas las cuotas (sin importar `estado`) de créditos desembolsados o ya pagados cuyo
     * vencimiento cae dentro de `[$desde, $hasta]` — a diferencia del resto del
     * calendario/dashboard, esta consulta necesita también las ya pagadas para pintarlas en
     * verde en el mes. Incluye `Pagado` porque un crédito liquidado en su última cuota pasa
     * de `Desembolsado` a `Pagado` en el mismo instante en que esa cuota se marca pagada.
     */
    public function delMesDeCreditosActivos(string $desde, string $hasta, ?int $empleadorId = null): Collection
    {
        return $this->consulta()
            ->whereBetween('fecha_vencimiento', [$desde, $hasta])
            ->whereHas('credito', fn ($consultaCredito) => $consultaCredito
                ->whereIn('estado', [EstadoCredito::Desembolsado, EstadoCredito::Pagado])
                ->when($empleadorId, fn ($consultaCliente) => $consultaCliente->whereHas('cliente', fn ($consultaCliente) => $consultaCliente->where('empleador_id', $empleadorId))))
            ->with(['credito.cliente.tercero'])
            ->get()
            ->sortBy(fn (Cuota $cuota) => $cuota->fecha_vencimiento->toDateString())
            ->values();
    }

    public function venceHoyDeCreditosActivos(): Collection
    {
        return $this->consulta()
            ->where('fecha_vencimiento', now()->toDateString())
            ->where('estado', '!=', EstadoCuota::Pagada)
            ->whereHas('credito', fn ($consultaCredito) => $consultaCredito->where('estado', EstadoCredito::Desembolsado))
            ->with(['credito.cliente.tercero'])
            ->get()
            ->sortBy(fn (Cuota $cuota) => $cuota->credito->cliente->tercero->nombre)
            ->values();
    }

    public function porEmpleador(int $empleadorId): Collection
    {
        return $this->consulta()
            ->whereHas('credito', function ($consultaCredito) use ($empleadorId) {
                $consultaCredito->where('estado', EstadoCredito::Desembolsado)
                    ->whereHas('cliente', fn ($consultaCliente) => $consultaCliente->where('empleador_id', $empleadorId));
            })
            ->with(['credito.cliente.tercero'])
            ->get()
            ->sortBy(fn (Cuota $cuota) => $cuota->credito->cliente->tercero->nombre.'|'.$cuota->fecha_vencimiento->format('Y-m-d'))
            ->values();
    }

    /**
     * Cuotas con interés aún sin devengar, vencidas hasta `$hasta`, de créditos desembolsados. La suspensión por
     * mora no va aquí (es una decisión de negocio, no un filtro de datos): la aplica `DevengoInteresService` con
     * `CuotaService::diasMora` sobre lo que esta consulta devuelve. Corre sin sesión (tarea programada): `Credito`
     * y `Cliente` no filtran sin `Auth`, así que `empresa_id` va explícito en ambos.
     */
    public function pendientesDeDevengoHasta(int $empresaId, string $hasta): Collection
    {
        return $this->consulta()
            ->whereNull('interes_devengado_en')
            ->where('valor_interes', '>', 0)
            ->where('fecha_vencimiento', '<=', $hasta)
            ->whereHas('credito', fn ($consultaCredito) => $consultaCredito
                ->where('empresa_id', $empresaId)
                ->where('estado', EstadoCredito::Desembolsado))
            ->with(['credito' => fn ($consultaCredito) => $consultaCredito
                ->where('empresa_id', $empresaId)
                ->with(['cliente' => fn ($consultaCliente) => $consultaCliente->where('empresa_id', $empresaId)->select('id', 'tercero_id')])])
            ->get();
    }

    /** @param list<int> $cuotaIds */
    public function marcarInteresDevengado(array $cuotaIds): void
    {
        foreach (array_chunk($cuotaIds, 1000) as $lote) {
            $this->consulta()->whereIn('id', $lote)->update(['interes_devengado_en' => now()]);
        }
    }

    public function cerrarPendientesComoOrigen(int $creditoId): void
    {
        $this->consulta()
            ->where('credito_id', $creditoId)
            ->where('estado', '!=', EstadoCuota::Pagada)
            ->update([
                'estado' => EstadoCuota::Pagada,
                'valor_pagado' => DB::raw('valor + valor_otros_gastos'),
            ]);
    }
}
