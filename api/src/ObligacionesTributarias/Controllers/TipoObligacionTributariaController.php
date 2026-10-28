<?php

namespace App\ObligacionesTributarias\Controllers;

use App\Http\Controllers\Controller;
use App\ObligacionesTributarias\Models\TipoObligacionTributaria;
use App\ObligacionesTributarias\Requests\CrearTipoObligacionRequest;
use App\ObligacionesTributarias\Requests\EditarTipoObligacionRequest;
use App\ObligacionesTributarias\Services\TipoObligacionTributariaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TipoObligacionTributariaController extends Controller
{
    public function __construct(protected TipoObligacionTributariaService $tipos) {}

    public function index(Request $peticion): JsonResponse
    {
        $soloActivos = $peticion->boolean('solo_activos');

        return response()->json(['data' => $this->tipos->listar($peticion->user(), $soloActivos)]);
    }

    public function store(CrearTipoObligacionRequest $peticion): JsonResponse
    {
        $tipo = $this->tipos->crear($peticion->user(), $peticion->validated());

        return response()->json(['tipo' => $tipo], 201);
    }

    public function update(TipoObligacionTributaria $tipo, EditarTipoObligacionRequest $peticion): JsonResponse
    {
        $tipo = $this->tipos->editar($tipo, $peticion->validated());

        return response()->json(['tipo' => $tipo]);
    }

    public function destroy(TipoObligacionTributaria $tipo): Response
    {
        $this->tipos->eliminar($tipo);

        return response()->noContent();
    }
}
