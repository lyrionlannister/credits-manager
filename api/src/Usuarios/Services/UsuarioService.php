<?php

namespace App\Usuarios\Services;

use App\Auditoria\Constants\AuditoriaConstants;
use App\Auditoria\Enums\AccionAuditoria;
use App\Auditoria\Services\EmisorAuditoria;
use App\Usuarios\Constants\UsuarioConstants;
use App\Usuarios\Models\Usuario;
use App\Usuarios\Repositories\PermisoRepository;
use App\Usuarios\Repositories\RolRepository;
use App\Usuarios\Repositories\UsuarioRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UsuarioService
{
    public function __construct(
        protected UsuarioRepository $usuarios,
        protected RolRepository $roles,
        protected PermisoRepository $permisos,
        protected EmisorAuditoria $auditoria,
        protected PermisoCache $cachePermisos,
    ) {}

    public function listarUsuarios(?string $busqueda, string $columna, string $direccion, int $porPagina, int $pagina): LengthAwarePaginator
    {
        $columnaValida = in_array($columna, UsuarioConstants::COLUMNAS_ORDENABLES, true) ? $columna : null;
        $direccionValida = $direccion === 'desc' ? 'desc' : 'asc';

        return $this->usuarios->paginar(
            busqueda: $busqueda,
            columna: $columnaValida,
            direccion: $direccionValida,
            porPagina: max(1, min(100, $porPagina)),
            pagina: max(1, $pagina),
        );
    }

    public function crearUsuario(array $datos): Usuario
    {
        $this->impedirEscaladaDePrivilegios(null, (int) $datos['rol_id'], $this->mapaPermisosExtra($datos));

        return DB::transaction(function () use ($datos) {
            $usuario = $this->usuarios->crear([
                'nombre' => $datos['nombre'],
                'usuario' => $datos['usuario'],
                'password_hash' => $datos['clave'],
                'rol_id' => $datos['rol_id'],
                'activo' => $datos['activo'],
                'es_asesor_global' => $datos['es_asesor_global'] ?? false,
            ]);

            $this->usuarios->sincronizarPermisosExtra($usuario, $this->mapaPermisosExtra($datos));
            // El modelo recién creado no trae `version_permisos` en memoria: sin refrescar,
            // increment() partiría de null y dejaría 1 en la respuesta aunque en base sea 2.
            $usuario->refresh();
            $usuario->increment('version_permisos');
            $this->cachePermisos->invalidar($usuario->fresh());

            $this->auditoria->registrarCreacion(AuditoriaConstants::MODULO_USUARIOS, "Usuario {$usuario->usuario} creado", $usuario, ['permisos_extra' => $this->permisosExtraDe($usuario)]);

            return $usuario;
        });
    }

    public function editarUsuario(Usuario $usuario, array $datos): Usuario
    {
        $this->impedirEscaladaDePrivilegios($usuario, (int) $datos['rol_id'], $this->mapaPermisosExtra($datos));
        $this->impedirAutoBloqueoDeAdmin($usuario, $datos['rol_id'], $datos['activo']);

        return DB::transaction(function () use ($usuario, $datos) {
            $anteriores = $this->auditoria->instantanea($usuario);
            $permisosAnteriores = $this->permisosExtraDe($usuario);

            $usuario = $this->usuarios->actualizar($usuario, [
                'nombre' => $datos['nombre'],
                'usuario' => $datos['usuario'],
                'rol_id' => $datos['rol_id'],
                'activo' => $datos['activo'],
                ...$this->alcanceAsesorAlEditar($datos),
            ]);

            $this->usuarios->sincronizarPermisosExtra($usuario, $this->mapaPermisosExtra($datos));
            $permisosNuevos = $this->permisosExtraDe($usuario);

            $this->auditoria->registrarModificacion(AuditoriaConstants::MODULO_USUARIOS, "Usuario {$usuario->usuario} modificado", $usuario, $anteriores);

            if ($permisosAnteriores !== $permisosNuevos || (int) $anteriores['rol_id'] !== (int) $usuario->rol_id) {
                $this->auditoria->registrar(
                    AccionAuditoria::CambioPermisos,
                    AuditoriaConstants::MODULO_USUARIOS,
                    "Permisos de {$usuario->usuario} modificados",
                    'usuario',
                    $usuario->id,
                    ['rol_id' => $anteriores['rol_id'], 'permisos_extra' => $permisosAnteriores],
                    ['rol_id' => $usuario->rol_id, 'permisos_extra' => $permisosNuevos],
                );
            }

            // La versión sube después de auditar: si se incrementara antes, `version_permisos`
            // aparecería como campo cambiado en el evento de modificación y un edit sin cambios
            // dejaría evento aunque nada cambió.
            $usuario->increment('version_permisos');
            $this->cachePermisos->invalidar($usuario->fresh());

            return $usuario;
        });
    }

    /** Un asesor que cambia a otro rol pierde el alcance global aunque el formulario no lo envíe (la base lo exige). */
    private function alcanceAsesorAlEditar(array $datos): array
    {
        if (! $this->roles->esRolAsesor($datos['rol_id'])) {
            return ['es_asesor_global' => false];
        }

        return array_key_exists('es_asesor_global', $datos) ? ['es_asesor_global' => $datos['es_asesor_global']] : [];
    }

    /**
     * Excepciones individuales: motivo obligatorio, responsable quien las crea y vencimiento
     * por defecto a 30 días (máximo 90, validado en el request).
     *
     * @return array<int, array{concedido: bool, empresa_id: int|null, motivo: string, responsable_id: int|null, vence_en: string}>
     */
    private function mapaPermisosExtra(array $datos): array
    {
        return collect($datos['permisos_extra'] ?? [])
            ->mapWithKeys(fn (array $excepcion) => [
                $excepcion['permiso_id'] => [
                    'concedido' => $excepcion['concedido'],
                    'motivo' => $excepcion['motivo'],
                    'responsable_id' => Auth::id(),
                    'vence_en' => $excepcion['vence_en'] ?? now()->addDays(30)->toDateTimeString(),
                ],
            ])
            ->all();
    }

    /** @return array<string, bool> "modulo.accion" => concedido, ordenado para poder comparar antes/después */
    private function permisosExtraDe(Usuario $usuario): array
    {
        $permisos = $usuario->permisosExtraVigentes()->get()
            ->mapWithKeys(fn ($permiso) => ["{$permiso->modulo}.{$permiso->accion}" => (bool) $permiso->pivot->concedido])
            ->all();

        ksort($permisos);

        return $permisos;
    }

    /**
     * La clave que pone el admin es temporal: el usuario la cambia en su próximo login
     * (`AuthService::cambiarClavePropia`). La propia la cambia el admin por `AuthController::cambiarClave`,
     * que sí exige la clave actual; este endpoint es solo para restablecer la de un tercero.
     */
    public function cambiarClave(Usuario $usuario, string $claveNueva): Usuario
    {
        if (Auth::id() === $usuario->id) {
            throw ValidationException::withMessages([
                'clave' => __('validation.custom.usuario.no_propia_clave'),
            ]);
        }

        $usuario = $this->usuarios->actualizar($usuario, ['password_hash' => $claveNueva, 'debe_cambiar_clave' => true]);

        $this->auditoria->registrar(AccionAuditoria::Modificar, AuditoriaConstants::MODULO_USUARIOS, "Contraseña de {$usuario->usuario} restablecida por el administrador", 'usuario', $usuario->id);

        return $usuario;
    }

    public function eliminarUsuario(Usuario $usuario): bool
    {
        $this->impedirEscaladaDePrivilegios($usuario, $usuario->rol_id, []);
        $this->impedirAutoBloqueoDeAdmin($usuario, $usuario->rol_id, false);

        return DB::transaction(function () use ($usuario) {
            $this->usuarios->actualizar($usuario, ['activo' => false]);

            $eliminado = $this->usuarios->eliminar($usuario);

            $this->auditoria->registrarEliminacion(AuditoriaConstants::MODULO_USUARIOS, "Usuario {$usuario->usuario} eliminado", $usuario);

            return $eliminado;
        });
    }

    /**
     * Los roles son de solo admin, pero `usuarios.crear`/`usuarios.editar` asignan rol y excepciones: sin este freno,
     * quien tiene esos permisos se daría a sí mismo (o a un cómplice) el rol admin o cualquier permiso. Un usuario que
     * no es admin no puede tocar a un admin ni conceder, por rol nuevo o excepción nueva, un permiso que él no tiene.
     * Lo que el usuario objetivo ya tenía no se revalida: editar su nombre no exige tener todos sus permisos.
     *
     * @param  array<int, array{concedido: bool}>  $permisosExtra  permiso_id => concedido
     */
    private function impedirEscaladaDePrivilegios(?Usuario $objetivo, int $rolId, array $permisosExtra): void
    {
        $actor = Auth::user();

        if ($actor === null || $this->roles->esRolAdmin($actor->rol_id)) {
            return;
        }

        $tocaAdmin = $this->roles->esRolAdmin($rolId) || ($objetivo !== null && $this->roles->esRolAdmin($objetivo->rol_id));

        $permisosQueSuma = $objetivo !== null && (int) $objetivo->rol_id === $rolId
            ? collect()
            : $this->roles->buscarPorIdOFallar($rolId)->permisos;

        $yaConcedidos = $objetivo?->permisosExtraVigentes()->wherePivot('concedido', true)->pluck('permisos.id')->map(fn ($id) => (int) $id)->all() ?? [];
        $extrasNuevos = collect($permisosExtra)
            ->filter(fn (array $excepcion, int|string $permisoId) => $excepcion['concedido'] && ! in_array((int) $permisoId, $yaConcedidos, true))
            ->keys();

        $permisosQueSuma = $permisosQueSuma->merge($this->permisos->consulta()->whereKey($extrasNuevos)->get());

        $concedeLoQueNoTiene = $permisosQueSuma->contains(fn ($permiso) => ! $actor->tienePermiso($permiso->modulo, $permiso->accion));

        if ($tocaAdmin || $concedeLoQueNoTiene) {
            // Fase 1 y Fase 10: el intento rechazado también queda en la bitácora, con el permiso que faltaba.
            // No hay cambio que revertir (nada se guardó), así que se registra como intento rechazado.
            $this->auditoria->registrar(
                AccionAuditoria::Rechazar,
                AuditoriaConstants::MODULO_USUARIOS,
                'Asignación de permisos rechazada por el freno anti-escalada',
                'usuario',
                $objetivo?->id,
                ['permisos_efectivos' => $objetivo !== null ? $this->permisosEfectivosDe($objetivo) : []],
                ['permisos_que_intentaba_conceder' => $permisosQueSuma->map(fn ($permiso) => "{$permiso->modulo}.{$permiso->accion}")->sort()->values()->all()],
                'No puedes conceder permisos que no tienes ni modificar a un administrador.',
            );

            abort(403, 'No puedes conceder permisos que no tienes ni modificar a un administrador.');
        }
    }

    /** @return list<string> permisos efectivos "modulo.accion" del usuario en su empresa, ordenados */
    private function permisosEfectivosDe(Usuario $usuario): array
    {
        $efectivos = $usuario->rol?->permisos()->get()
            ->map(fn ($permiso) => "{$permiso->modulo}.{$permiso->accion}")
            ->all() ?? [];

        foreach ($usuario->permisosExtraVigentes()->get() as $permiso) {
            $clave = "{$permiso->modulo}.{$permiso->accion}";

            if ($permiso->pivot->concedido) {
                $efectivos[] = $clave;
            } else {
                $efectivos = array_values(array_diff($efectivos, [$clave]));
            }
        }

        sort($efectivos);

        return array_values(array_unique($efectivos));
    }

    /**
     * Replica la protección de usuarios/guardar.php: un admin no puede quitarse
     * a sí mismo el rol de admin ni desactivarse si es el último admin activo.
     */
    private function impedirAutoBloqueoDeAdmin(Usuario $usuario, int $nuevoRolId, bool $nuevoActivo): void
    {
        $esElUsuarioAutenticado = Auth::id() === $usuario->id;
        $perderiaAdmin = ! $this->roles->esRolAdmin($nuevoRolId) || ! $nuevoActivo;

        if (! $esElUsuarioAutenticado || ! $perderiaAdmin) {
            return;
        }

        if ($this->usuarios->contarAdminsActivos() <= 1) {
            throw ValidationException::withMessages([
                'rol_id' => __('validation.custom.usuario.unico_admin_activo'),
            ]);
        }
    }
}
