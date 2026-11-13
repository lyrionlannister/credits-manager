<?php

namespace App\Usuarios\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class UsuarioPermiso extends Pivot
{
    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'ultima_actualizacion';

    protected $table = 'usuario_permiso';

    protected static function booted(): void
    {
        // Red de seguridad: una excepción creada sin empresa explícita hereda la del usuario.
        // El servicio siempre la pasa explícita; esto cubre attach() directos y tests antiguos.
        static::creating(function (UsuarioPermiso $pivote) {
            if ($pivote->empresa_id === null && $pivote->usuario_id !== null) {
                $pivote->empresa_id = Usuario::whereKey($pivote->usuario_id)->value('empresa_id');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'concedido' => 'boolean',
            'vence_en' => 'datetime',
        ];
    }
}
