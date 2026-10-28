<?php

namespace App\Metas\Controllers;

use App\Core\Dinero\Dinero;
use App\Http\Controllers\Controller;
use App\Metas\Constants\MetasConstants;
use App\Metas\Data\PeriodoMeta;
use App\Metas\Enums\TipoMeta;
use App\Metas\Models\Meta;
use App\Metas\Requests\CrearMetaRequest;
use App\Metas\Requests\DistribuirMetaRequest;
use App\Metas\Requests\EditarMetaRequest;
use App\Metas\Requests\ListarMetasRequest;
use App\Metas\Services\MetaService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class MetaController extends Controller
{
    public function __construct(protected MetaService $metas) {}

    public function index(ListarMetasRequest $peticion): JsonResponse
    {
        $hoy = CarbonImmutable::now(MetasConstants::ZONA_HORARIA);
        $periodo = PeriodoMeta::desde($peticion->validated('periodo') ?? PeriodoMeta::actual($hoy)->codigo());

        return response()->json($this->metas->listar($peticion->user(), $periodo, $hoy));
    }

    public function store(CrearMetaRequest $peticion): JsonResponse
    {
        $meta = $this->metas->crear(
            $peticion->user(),
            PeriodoMeta::desde($peticion->validated('periodo')),
            TipoMeta::from($peticion->validated('tipo')),
            Dinero::desdePesos($peticion->validated('valor_objetivo')),
        );

        return response()->json(['meta' => $meta->load('asesores')], 201);
    }

    public function update(Meta $meta, EditarMetaRequest $peticion): JsonResponse
    {
        $meta = $this->metas->editar($meta, Dinero::desdePesos($peticion->validated('valor_objetivo')));

        return response()->json(['meta' => $meta->load('asesores')]);
    }

    public function distribuir(Meta $meta, DistribuirMetaRequest $peticion): JsonResponse
    {
        $filas = array_map(fn (array $fila) => [
            'usuario_id' => (int) $fila['usuario_id'],
            'valor_objetivo' => Dinero::desdePesos($fila['valor_objetivo']),
        ], $peticion->validated('asesores'));

        return response()->json(['meta' => $this->metas->distribuir($peticion->user(), $meta, $filas)]);
    }

    public function destroy(Meta $meta): Response
    {
        $this->metas->eliminar($meta);

        return response()->noContent();
    }
}
