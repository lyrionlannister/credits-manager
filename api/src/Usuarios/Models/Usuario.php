<?php

namespace App\Usuarios\Models;

use App\Core\Base\BaseModel;
use App\Core\Concerns\PerteneceAEmpresa;
use App\Usuarios\Constants\UsuarioConstants;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['empresa_id', 'nombre', 'usuario', 'password_hash', 'rol_id', 'activo', 'es_asesor_global'])]
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

    /** Excepciones puntuales sobre el permiso que da el rol: conceden o revocan un permiso específico. */
    public function permisosExtra(): BelongsToMany
    {
        return $this->belongsToMany(Permiso::class, 'usuario_permiso')
            ->using(UsuarioPermiso::class)
            ->withPivot(['concedido']);
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

        $excepcion = $this->permisosExtra()
            ->where('modulo', $modulo)
            ->where('accion', $accion)
            ->first();

        if ($excepcion) {
            return (bool) $excepcion->pivot->concedido;
        }

        return (bool) $this->rol?->permisos()
            ->where('modulo', $modulo)
            ->where('accion', $accion)
            ->exists();
    }

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'activo' => 'boolean',
            'es_asesor_global' => 'boolean',
        ];
    }
}
