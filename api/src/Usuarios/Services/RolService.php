<?php

namespace App\Usuarios\Services;

use App\Auditoria\Constants\AuditoriaConstants;
use App\Auditoria\Enums\AccionAuditoria;
use App\Auditoria\Services\EmisorAuditoria;
use App\Usuarios\Constants\UsuarioConstants;
use App\Usuarios\Models\Rol;
use App\Usuarios\Models\Usuario;
use App\Usuarios\Services\PermisoCache;
use App\Usuarios\Repositories\RolRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RolService
{
    public function __construct(
        protected RolRepository $roles,
        protected EmisorAuditoria $auditoria,
        protected PermisoCache $cachePermisos,
    ) {}

    public function paginar(?string $busqueda, int $porPagina, int $pagina): LengthAwarePaginator
    {
        return $this->roles->paginar($busqueda, max(1, min(100, $porPagina)), max(1, $pagina));
    }

    public function crear(array $datos): Rol
    {
        return DB::transaction(function () use ($datos) {
            $rol = $this->roles->crear([
                'nombre' => $datos['nombre'],
                'descripcion' => $datos['descripcion'] ?? null,
            ]);

            $this->roles->sincronizarPermisos($rol, $datos['permisos'] ?? []);

            $this->auditoria->registrarCreacion(AuditoriaConstants::MODULO_USUARIOS, "Rol {$rol->nombre} creado", $rol, ['permisos' => $this->permisosDe($rol)]);

            return $rol;
        });
    }

    public function editar(Rol $rol, array $datos): Rol
    {
        if (in_array($rol->nombre, UsuarioConstants::ROLES_PROTEGIDOS, true) && $datos['nombre'] !== $rol->nombre) {
            throw ValidationException::withMessages([
                'nombre' => __("validation.custom.rol.no_renombrar_{$rol->nombre}"),
            ]);
        }

        return DB::transaction(function () use ($rol, $datos) {
            $anteriores = $this->auditoria->instantanea($rol);
            $permisosAnteriores = $this->permisosDe($rol);

            $rol = $this->roles->actualizar($rol, [
                'nombre' => $datos['nombre'],
                'descripcion' => $datos['descripcion'] ?? null,
            ]);

            $this->roles->sincronizarPermisos($rol, $datos['permisos'] ?? []);
            $permisosNuevos = $this->permisosDe($rol);

            $this->auditoria->registrarModificacion(AuditoriaConstants::MODULO_USUARIOS, "Rol {$rol->nombre} modificado", $rol, $anteriores);

            if ($permisosAnteriores !== $permisosNuevos) {
                // Fase 3 y 9: la versión de permisos de cada usuario del rol cambia con él,
                // y su caché se invalida (con evento para los demás procesos).
                Usuario::where('rol_id', $rol->id)->increment('version_permisos');
                Usuario::where('rol_id', $rol->id)->get()->each(fn (Usuario $usuario) => $this->cachePermisos->invalidar($usuario->fresh()));

                $this->auditoria->registrar(
                    AccionAuditoria::CambioPermisos,
                    AuditoriaConstants::MODULO_USUARIOS,
                    "Permisos del rol {$rol->nombre} modificados",
                    'rol',
                    $rol->id,
                    ['permisos' => $permisosAnteriores],
                    ['permisos' => $permisosNuevos],
                );
            }

            return $rol;
        });
    }

    /** @return list<string> "modulo.accion" ordenados, para comparar antes/después */
    private function permisosDe(Rol $rol): array
    {
        return $rol->permisos()->get()
            ->map(fn ($permiso) => "{$permiso->modulo}.{$permiso->accion}")
            ->sort()
            ->values()
            ->all();
    }

    public function eliminar(Rol $rol): bool
    {
        if (in_array($rol->nombre, UsuarioConstants::ROLES_PROTEGIDOS, true)) {
            throw ValidationException::withMessages([
                'rol' => __("validation.custom.rol.no_eliminar_{$rol->nombre}"),
            ]);
        }

        if ($this->roles->tieneUsuariosActivos($rol->id)) {
            throw ValidationException::withMessages([
                'rol' => __('validation.custom.rol.tiene_usuarios_activos'),
            ]);
        }

        $eliminado = $this->roles->eliminar($rol);

        $this->auditoria->registrarEliminacion(AuditoriaConstants::MODULO_USUARIOS, "Rol {$rol->nombre} eliminado", $rol);

        return $eliminado;
    }
}
