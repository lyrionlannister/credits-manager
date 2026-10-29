<?php

namespace App\Pagos\Repositories;

use App\Core\Base\BaseRepository;
use App\Pagos\Models\Pago;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class PagoRepository extends BaseRepository
{
    public function __construct(Pago $pago)
    {
        parent::__construct($pago);
    }

    /** Todos los pagos de la empresa (`PerteneceAEmpresa` en el modelo ya filtra), no
     * atados a un crédito puntual — para el listado global del módulo Pagos. */
    public function paginar(?string $busqueda, ?string $mes, int $porPagina, int $pagina): LengthAwarePaginator
    {
        $consulta = $this->consulta()
            ->with(['cliente.tercero', 'credito', 'recibo:id,pago_id,numero,estado', 'reversa:id,pago_id,fecha,motivo'])
            ->orderByDesc('fecha_pago')
            ->orderByDesc('id');

        return $this->filtrarMes($this->filtrar($consulta, $busqueda), $mes)->paginate(perPage: $porPagina, page: $pagina);
    }

    /** Mismo listado que la pantalla, con empresa y asesor explícitos, para exportar. */
    public function consultaParaExportar(int $empresaId, ?int $asesorId, ?string $busqueda, ?string $mes): Builder
    {
        $consulta = $this->consulta()
            ->where('pagos.empresa_id', $empresaId)
            ->with(['cliente.tercero', 'recibo:id,pago_id,numero,estado', 'reversa:id,pago_id,fecha,motivo'])
            ->orderByDesc('fecha_pago')
            ->orderByDesc('id');

        if ($asesorId !== null) {
            $consulta->whereIn('pagos.cliente_id', DB::table('clientes')->select('id')->where('empresa_id', $empresaId)->where('asesor_id', $asesorId));
        }

        return $this->filtrarMes($this->filtrar($consulta, $busqueda), $mes);
    }

    public function cursorParaExportar(int $empresaId, ?int $asesorId, ?string $busqueda, ?string $mes): LazyCollection
    {
        return $this->consultaParaExportar($empresaId, $asesorId, $busqueda, $mes)->lazy();
    }

    public function contarParaExportar(int $empresaId, ?int $asesorId, ?string $busqueda, ?string $mes): int
    {
        return $this->consultaParaExportar($empresaId, $asesorId, $busqueda, $mes)->reorder()->count();
    }

    private function filtrar(Builder $consulta, ?string $busqueda): Builder
    {
        if ($busqueda) {
            $consulta->whereHas('cliente.tercero', fn ($q) => $q->where('nombre', 'like', "%{$busqueda}%"));
        }

        return $consulta;
    }

    /** Mes calendario en hora de Bogotá, `YYYY-MM`: rango semiabierto para que el índice por fecha sirva. */
    private function filtrarMes(Builder $consulta, ?string $mes): Builder
    {
        if ($mes === null) {
            return $consulta;
        }

        $inicio = CarbonImmutable::createFromFormat('Y-m-d', $mes.'-01', 'America/Bogota')->startOfMonth();

        return $consulta->where('pagos.fecha_pago', '>=', $inicio)->where('pagos.fecha_pago', '<', $inicio->addMonth());
    }

    public function porCredito(int $creditoId, string $direccion = 'desc'): Collection
    {
        return $this->consulta()
            ->with(['recibo:id,pago_id,numero,estado', 'reversa:id,pago_id,fecha,motivo'])
            ->where('credito_id', $creditoId)
            ->orderBy('fecha_pago', $direccion)
            ->get();
    }

    public function porCliente(int $clienteId, int $limite = 10): Collection
    {
        return $this->consulta()
            ->with('recibo:id,pago_id,numero,estado')
            ->where('cliente_id', $clienteId)
            ->orderByDesc('fecha_pago')
            ->limit($limite)
            ->get();
    }

    public function ultimoPago(int $creditoId): ?Pago
    {
        return $this->consulta()->where('credito_id', $creditoId)->orderByDesc('fecha_pago')->first();
    }

    /** Último pago aplicado al crédito que no está reversado: el único que admite reversa, para no reordenar la aplicación. */
    public function ultimoVigenteDelCredito(int $creditoId): ?Pago
    {
        return $this->consulta()
            ->where('credito_id', $creditoId)
            ->whereDoesntHave('reversa')
            ->orderByDesc('id')
            ->first();
    }
}
