<?php

namespace App\Creditos\Models;

use App\Core\Base\BaseModel;
use App\Core\Concerns\PerteneceAEmpresa;
use App\Core\Dinero\DineroCast;
use App\Creditos\Enums\TipoReprogramacion;
use App\Pagos\Models\Pago;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['credito_id', 'pago_id', 'tipo', 'valor_abono', 'motivo', 'plan_anterior', 'creado_por'])]
class ReprogramacionCredito extends BaseModel
{
    use PerteneceAEmpresa;

    protected $table = 'reprogramaciones_credito';

    protected function casts(): array
    {
        return [
            'tipo' => TipoReprogramacion::class,
            'valor_abono' => DineroCast::class,
            'plan_anterior' => 'array',
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class);
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }
}
