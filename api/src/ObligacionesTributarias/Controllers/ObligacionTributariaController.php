<?php

namespace App\ObligacionesTributarias\Controllers;

use App\Http\Controllers\Controller;
use App\ObligacionesTributarias\Constants\ObligacionesTributariasConstants;
use App\ObligacionesTributarias\Enums\EstadoObligacionTributaria;
use App\ObligacionesTributarias\Models\ObligacionTributaria;
use App\ObligacionesTributarias\Requests\CambiarEstadoObligacionRequest;
use App\ObligacionesTributarias\Requests\CrearObligacionRequest;
use App\ObligacionesTributarias\Requests\EditarObligacionRequest;
use App\ObligacionesTributarias\Requests\ListarObligacionesRequest;
use App\ObligacionesTributarias\Services\ObligacionTributariaService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ObligacionTributariaController extends Controller
{
    public function __construct(protected ObligacionTributariaService $obligaciones) {}

    public function index(ListarObligacionesRequest $peticion): JsonResponse
    {
        $hoy = CarbonImmutable::now(ObligacionesTributariasConstants::ZONA_HORARIA);

        return response()->json(['data' => $this->obligaciones->listar($peticion->user(), $peticion->validated(), $hoy)]);
    }

    public function store(CrearObligacionRequest $peticion): JsonResponse
    {
        $obligacion = $this->obligaciones->crear($peticion->user(), $peticion->validated());

        return response()->json(['obligacion' => $obligacion], 201);
    }

    public function update(ObligacionTributaria $obligacion, EditarObligacionRequest $peticion): JsonResponse
    {
        $obligacion = $this->obligaciones->editar($obligacion, $peticion->validated());

        return response()->json(['obligacion' => $obligacion]);
    }

    public function cambiarEstado(ObligacionTributaria $obligacion, CambiarEstadoObligacionRequest $peticion): JsonResponse
    {
        $estado = EstadoObligacionTributaria::from($peticion->validated('estado'));
        $obligacion = $this->obligaciones->cambiarEstado($peticion->user(), $obligacion, $estado);

        return response()->json(['obligacion' => $obligacion]);
    }

    public function destroy(ObligacionTributaria $obligacion): Response
    {
        $this->obligaciones->eliminar($obligacion);

        return response()->noContent();
    }

    public function responsablesAsignables(): JsonResponse
    {
        return response()->json(['data' => $this->obligaciones->responsablesAsignables()]);
    }
}
