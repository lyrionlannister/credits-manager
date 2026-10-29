<?php

namespace App\Empresas\Models;

use App\Core\Base\BaseModel;
use App\Gastos\Models\ActividadEconomica;
use App\Geografia\Models\Municipio;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['nombre_empresa', 'nit', 'digito_verificacion', 'direccion', 'municipio_id', 'actividad_economica_id', 'responsable_iva', 'autorretenedor', 'telefono', 'correo', 'logo_path', 'logo_pdf_path'])]
class Empresa extends BaseModel
{
    protected $table = 'empresas';

    protected function casts(): array
    {
        return [
            'municipio_id' => 'integer',
            'actividad_economica_id' => 'integer',
            'responsable_iva' => 'boolean',
            'autorretenedor' => 'boolean',
        ];
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class);
    }

    public function actividadEconomica(): BelongsTo
    {
        return $this->belongsTo(ActividadEconomica::class);
    }

    /** NIT tal como se imprime, con su dígito de verificación. */
    public function nitConDigito(): ?string
    {
        if (! $this->nit) {
            return null;
        }

        return $this->digito_verificacion !== null
            ? "{$this->nit}-{$this->digito_verificacion}"
            : $this->nit;
    }
}
