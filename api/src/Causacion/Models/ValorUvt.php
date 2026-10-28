<?php

namespace App\Causacion\Models;

use App\Core\Base\BaseModel;
use App\Core\Concerns\PerteneceAEmpresa;
use App\Core\Dinero\DineroCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * La UVT la fija la DIAN, pero puede cambiar más de una vez en el mismo año; cada empresa administra la suya por
 * su cuenta (un admin no puede alterar la de otra). `fecha_hasta` nula es la vigencia abierta (la actual).
 */
#[Fillable(['empresa_id', 'fecha_desde', 'fecha_hasta', 'valor'])]
class ValorUvt extends BaseModel
{
    use PerteneceAEmpresa;

    protected $table = 'valores_uvt';

    protected function casts(): array
    {
        return [
            'fecha_desde' => 'date:Y-m-d',
            'fecha_hasta' => 'date:Y-m-d',
            'valor' => DineroCast::class,
        ];
    }
}
