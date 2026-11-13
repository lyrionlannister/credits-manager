<?php

namespace App\Usuarios\Models;

use App\Core\Base\BaseModel;
use App\Core\Concerns\PerteneceAEmpresa;
use App\Usuarios\Constants\UsuarioConstants;
use App\Usuarios\Services\PermisoCache;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['empresa_id', 'nombre', 'usuario', 'password_hash', 'debe_cambiar_clave', 'rol_id', 'activo', 'es_asesor_global'])]
#[Hidden(['password_hash', 'clave_superadmin'])]
class Usuario extends BaseModel implements AuthenticatableContract
{
    use Authenticatable;
    use PerteneceAEmpresa;

    protected $table = 'usuarios';

    protected $appends = ['es_superadmin'];

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    protected function usuario(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtolower($value),
        );
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    /**
     * Excepciones puntuales sobre el permiso que da el rol: conceden o revocan un permiso específico. Solo se leen las de la empresa del usuario: una excepción de otra empresa nunca se hereda.
     *
     * El filtro por empresa solo se aplica si el modelo existe: en eager loading (`with`/`load`) Eloquent
     * construye esta relación sobre una instancia vacía (sin empresa_id) para armar la consulta plantilla
     * y luego la restringe por `usuario_id`; aplicar el filtro ahí dejaría la consulta sin resultados.
     * Como cada usuario_id ya pertenece a una sola empresa, el filtro es defensa en profundidad, no la
     * restricción real.
     */
    public function permisosExtra(): BelongsToMany
    {
        $relacion = $this->belongsToMany(Permiso::class, 'usuario_permiso')
            ->using(UsuarioPermiso::class)
            ->withPivot(['concedido', 'empresa_id', 'motivo', 'responsable_id', 'vence_en']);

        if ($this->exists) {
            $relacion->wherePivot('empresa_id', $this->empresa_id);
        }

        return $relacion;
    }

    /** Solo las excepciones vigentes: sin vencimiento (pendientes de regularizar) o con vence_en en el futuro. Una vencida deja de aplicar y el rol vuelve a mandar. */
    public function permisosExtraVigentes(): BelongsToMany
    {
        return $this->permisosExtra()
            ->where(function ($consulta) {
                $consulta->whereNull('usuario_permiso.vence_en')
                    ->orWhere('usuario_permiso.vence_en', '>', now());
            });
    }

    /** Restricción reutilizable para cargar `permisosExtra` solo con las vigentes (p. ej. en /me). */
    public static function soloVigentes(): \Closure
    {
        return function ($relacion) {
            $relacion->where(function ($consulta) {
                $consulta->whereNull('usuario_permiso.vence_en')
                    ->orWhere('usuario_permiso.vence_en', '>', now());
            });
        };
    }

    /**
     * Lo da el rol, no una columna. Un usuario de empresa no carga el rol para responder: así un listado de usuarios
     * no hace una consulta por fila. La base garantiza que sin empresa ⇔ rol Super Admin (triggers de `usuarios`).
     * El rol se carga con el scope de la sesión: se evalúa sin sesión (login) o con la del propio Super Admin.
     */
    protected function esSuperadmin(): Attribute
    {
        return Attribute::get(fn (): bool => $this->empresa_id === null && $this->rol?->nombre === UsuarioConstants::ROL_SUPERADMIN);
    }

    /** Solo el rol asesor sin `es_asesor_global` queda limitado a sus clientes asignados; los demás roles ven toda su empresa. */
    public function restringidoAClientesPropios(): bool
    {
        return $this->rol?->nombre === UsuarioConstants::ROL_ASESOR && ! $this->es_asesor_global;
    }

    /** Id del asesor cuyo alcance limita los datos, o null si el usuario ve toda su empresa. */
    public function idAsesorConAlcance(): ?int
    {
        return $this->restringidoAClientesPropios() ? $this->id : null;
    }

    /** El total de cartera es siempre individual para un asesor, incluso con alcance global de clientes. */
    public function idAsesorParaCartera(): ?int
    {
        return $this->rol?->nombre === UsuarioConstants::ROL_ASESOR ? $this->id : null;
    }

    public function tienePermiso(string $modulo, string $accion): bool
    {
        if ($this->rol?->nombre === UsuarioConstants::ROL_ADMIN) {
            return true;
        }

        // Fase 9: el permiso efectivo sale del caché por (usuario, empresa); si Redis cae, el
        // caché calcula contra la base de datos. La excepción vigente siempre gana sobre el rol.
        return in_array("{$modulo}.{$accion}", app(PermisoCache::class)->efectivos($this), true);
    }

    /** @return list<string> permisos efectivos "modulo.accion" de este usuario en su empresa */
    public function permisosEfectivos(): array
    {
        if ($this->rol?->nombre === UsuarioConstants::ROL_ADMIN) {
            return ['*'];
        }

        return app(PermisoCache::class)->efectivos($this);
    }

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'activo' => 'boolean',
            'debe_cambiar_clave' => 'boolean',
            'es_asesor_global' => 'boolean',
            'version_permisos' => 'integer',
        ];
    }
}
