<?php

namespace App\Creditos\Repositories;

use App\Core\Base\BaseRepository;
use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Models\Credito;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class CreditoRepository extends BaseRepository
{
    public function __construct(Credito $credito)
    {
        parent::__construct($credito);
    }

    /**
     * Créditos desembolsados de la empresa, con su cliente. La empresa y el asesor van explícitos: la cartera
     * para exportar corre en el worker, sin sesión, donde los scopes no filtran.
     */
    public function activosDeEmpresa(int $empresaId, ?int $asesorId): Collection
    {
        return $this->restringirAAsesor($this->consultaDeEmpresa($empresaId), $empresaId, $asesorId)
            ->where('estado', EstadoCredito::Desembolsado)
            ->with('cliente.tercero')
            ->get()
            ->sortBy(fn (Credito $credito) => $credito->cliente->tercero->nombre)
            ->values();
    }

    public function contarActivos(): int
    {
        return $this->consulta()->where('estado', EstadoCredito::Desembolsado)->count();
    }

    /** Solo los ids: el dashboard contable solo necesita cruzarlos con `CuotaRepository::resumenPorCreditos()`. */
    public function idsVigentesDeEmpresa(int $empresaId): array
    {
        return $this->consultaDeEmpresa($empresaId)->where('estado', EstadoCredito::Desembolsado)->pluck('id')->all();
    }

    public function paginar(?string $busqueda, int $porPagina, int $pagina, ?int $clienteId = null, ?EstadoCredito $estado = null): LengthAwarePaginator
    {
        $consulta = $this->consulta()
            ->with('cliente.tercero')
            ->withCount(['cuotas as cuotas_vencidas' => fn ($q) => $q->where('estado', EstadoCuota::Vencida)])
            ->withCount('pagos')
            ->orderByDesc('creado_en');

        return $this->filtrar($consulta, $busqueda, $clienteId, $estado)->paginate(perPage: $porPagina, page: $pagina);
    }

    /** Con la empresa explícita: el estado de cuenta para exportar se resuelve en el worker, sin sesión. */
    public function buscarDeEmpresaOFallar(int $empresaId, int $id): Credito
    {
        return $this->consultaDeEmpresa($empresaId)->findOrFail($id);
    }

    /** Mismo listado que la pantalla, con empresa y asesor explícitos, para exportar. */
    /**
     * Créditos por cliente sin contar los cancelados (los eliminados ya quedan fuera por `borrado_en`).
     *
     * @param  list<int>  $clienteIds
     * @return array<int, int>
     */
    public function conteoPorClientes(array $clienteIds): array
    {
        if ($clienteIds === []) {
            return [];
        }

        return $this->consulta()
            ->whereIn('cliente_id', $clienteIds)
            ->where('estado', '!=', EstadoCredito::Cancelado)
            ->selectRaw('cliente_id, COUNT(*) AS total')
            ->groupBy('cliente_id')
            ->pluck('total', 'cliente_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    public function consultaParaExportar(int $empresaId, ?int $asesorId, ?string $busqueda, ?EstadoCredito $estado): Builder
    {
        $consulta = $this->restringirAAsesor($this->consultaDeEmpresa($empresaId), $empresaId, $asesorId)
            ->with('cliente.tercero')
            ->orderByDesc('creado_en');

        return $this->filtrar($consulta, $busqueda, null, $estado);
    }

    public function cursorParaExportar(int $empresaId, ?int $asesorId, ?string $busqueda, ?EstadoCredito $estado): LazyCollection
    {
        return $this->consultaParaExportar($empresaId, $asesorId, $busqueda, $estado)->lazy();
    }

    public function contarParaExportar(int $empresaId, ?int $asesorId, ?string $busqueda, ?EstadoCredito $estado): int
    {
        return $this->consultaParaExportar($empresaId, $asesorId, $busqueda, $estado)->reorder()->count();
    }

    private function consultaDeEmpresa(int $empresaId): Builder
    {
        return $this->consulta()->where('creditos.empresa_id', $empresaId);
    }

    private function restringirAAsesor(Builder $consulta, int $empresaId, ?int $asesorId): Builder
    {
        if ($asesorId === null) {
            return $consulta;
        }

        return $consulta->whereIn(
            'creditos.cliente_id',
            DB::table('clientes')->select('id')->where('empresa_id', $empresaId)->where('asesor_id', $asesorId),
        );
    }

    private function filtrar(Builder $consulta, ?string $busqueda, ?int $clienteId, ?EstadoCredito $estado): Builder
    {
        if ($busqueda) {
            $consulta->whereHas('cliente.tercero', fn ($q) => $q->where('nombre', 'like', "%{$busqueda}%"));
        }

        if ($clienteId) {
            $consulta->where('cliente_id', $clienteId);
        }

        if ($estado) {
            $consulta->where('estado', $estado);
        }

        return $consulta;
    }

    public function obtenerConRelaciones(Credito $credito): Credito
    {
        return $credito->load(['cliente.tercero', 'cuotas', 'credito_origen.cliente.tercero', 'refinanciado_por']);
    }

    public function marcarComoPagado(Credito $credito): void
    {
        $this->actualizar($credito, ['estado' => EstadoCredito::Pagado]);
    }

    public function marcarComoRefinanciado(Credito $credito): void
    {
        $this->actualizar($credito, ['estado' => EstadoCredito::Refinanciado]);
    }

    /** Bloquea la fila del original: dos desembolsos simultáneos no pueden refinanciar el mismo crédito. */
    public function buscarParaCongelar(int $id): Credito
    {
        return $this->consulta()->lockForUpdate()->findOrFail($id);
    }

    /** Bloquea la fila: dos ediciones o una aprobación simultánea no pueden pisarse. */
    public function bloquear(int $id): Credito
    {
        return $this->consulta()->lockForUpdate()->findOrFail($id);
    }

    public function existeRefinanciacionEnCurso(int $origenId): bool
    {
        return $this->consulta()
            ->where('credito_origen_id', $origenId)
            ->whereIn('estado', [EstadoCredito::Solicitado, EstadoCredito::Aprobado])
            ->exists();
    }
}
