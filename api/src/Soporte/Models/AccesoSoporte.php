<?php

namespace App\Soporte\Models;

use App\Core\Base\BaseModel;
use App\Empresas\Models\Empresa;
use App\Soporte\Enums\EstadoAccesoSoporte;
use App\Usuarios\Models\Usuario;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Acceso de soporte just-in-time (Fase 4). Sin `PerteneceAEmpresa` a propósito: lo crean y lo
 * consultan superadmins (sin empresa) y administradores de la empresa solicitada, y el filtro
 * por empresa lo decide el servicio, no el scope global.
 */
#[Fillable([
    'superadmin_id', 'empresa_id', 'admin_aprobador_id', 'motivo', 'estado',
    'duracion_minutos', 'solicitado_en', 'aprobado_en', 'activado_en', 'expira_en', 'revocado_en',
])]
class AccesoSoporte extends BaseModel
{
    protected $table = 'accesos_soporte';

    protected function casts(): array
    {
        return [
            'superadmin_id' => 'integer',
            'empresa_id' => 'integer',
            'admin_aprobador_id' => 'integer',
            'estado' => EstadoAccesoSoporte::class,
            'duracion_minutos' => 'integer',
            'solicitado_en' => 'datetime',
            'aprobado_en' => 'datetime',
            'activado_en' => 'datetime',
            'expira_en' => 'datetime',
            'revocado_en' => 'datetime',
        ];
    }

    public function superadmin(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'superadmin_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function adminAprobador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'admin_aprobador_id');
    }

    public function estaVigente(): bool
    {
        return $this->estado === EstadoAccesoSoporte::Activo
            && $this->expira_en !== null
            && $this->expira_en->isFuture();
    }
}
