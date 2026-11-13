<?php

namespace App\Usuarios\Repositories;

use App\Core\Base\BaseRepository;
use App\Usuarios\Constants\UsuarioConstants;
use App\Usuarios\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class UsuarioRepository extends BaseRepository
{
    public function __construct(Usuario $usuario)
    {
        parent::__construct($usuario);
    }

    public function buscarActivoPorUsuario(string $usuario): ?Usuario
    {
        return $this->consulta()
            ->where('usuario', $usuario)
            ->where('activo', true)
            ->first();
    }

    /** Autor temporal para procesos sin sesión que necesitan un `Auth::user()` de la empresa (ver `conAutor`). */
    public function primerActivoDeEmpresa(int $empresaId): ?Usuario
    {
        return $this->consulta()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->orderBy('id')
            ->first();
    }

    /** Incluye inactivos y dados de baja: solo para explicar por qué falló un inicio de sesión. */
    public function buscarPorUsuario(string $usuario): ?Usuario
    {
        return $this->consulta()
            ->withTrashed()
            ->where('usuario', $usuario)
            ->first();
    }

    /**
     * Usuarios activos de la empresa con el permiso dado (por rol o por excepción). Se evalúa uno a uno con
     * `tienePermiso`, que ya resuelve el rol admin y las excepciones concedidas o revocadas; son pocos por empresa.
     */
    public function activosConPermiso(string $modulo, string $accion): Collection
    {
        return $this->consulta()
            ->where('activo', true)
            ->with('rol')
            ->get()
            ->filter(fn (Usuario $usuario) => $usuario->tienePermiso($modulo, $accion))
            ->values();
    }

    /** Usuarios activos a quienes se puede asignar algo (un cliente, una obligación...). Por defecto, los roles asignables de cartera. */
    public function asignables(?array $roles = null): Collection
    {
        return $this->consulta()
            ->select(['id', 'nombre', 'usuario', 'rol_id'])
            ->where('activo', true)
            ->whereHas('rol', fn (Builder $consulta) => $consulta->whereIn('nombre', $roles ?? UsuarioConstants::ROLES_ASIGNABLES))
            ->with('rol:id,nombre')
            ->orderBy('nombre')
            ->get();
    }

    public function paginar(?string $busqueda, ?string $columna, string $direccion, int $porPagina, int $pagina): LengthAwarePaginator
    {
        $consulta = $this->consulta()->with('rol');

        if ($busqueda) {
            $consulta->where(function (Builder $subconsulta) use ($busqueda) {
                $subconsulta->where('nombre', 'like', "%{$busqueda}%")
                    ->orWhere('usuario', 'like', "%{$busqueda}%");
            });
        }

        if ($columna) {
            $consulta->orderBy($columna, $direccion);
        }

        return $consulta->paginate(perPage: $porPagina, page: $pagina);
    }

    /** Sincroniza las excepciones de la empresa del usuario. La relación `permisosExtra()` ya viene filtrada por esa empresa, así que el sync no toca excepciones de otras empresas. */
    public function sincronizarPermisosExtra(Usuario $usuario, array $mapa): void
    {
        $mapa = collect($mapa)
            ->mapWithKeys(fn (array $valores, int|string $permisoId) => [
                (int) $permisoId => [...$valores, 'empresa_id' => $usuario->empresa_id],
            ])
            ->all();

        $usuario->permisosExtra()->sync($mapa);
    }

    public function contarAdminsActivos(): int
    {
        return $this->consulta()
            ->whereHas('rol', fn (Builder $consulta) => $consulta->where('nombre', UsuarioConstants::ROL_ADMIN))
            ->where('activo', true)
            ->count();
    }
}
