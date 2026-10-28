<?php

namespace App\Gastos\Controllers;

use App\Causacion\Models\ValorUvt;
use App\Causacion\Services\ValorUvtService;
use App\Core\Dinero\Dinero;
use App\Gastos\Requests\EditarValorUvtRequest;
use App\Gastos\Requests\GuardarValorUvtRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ValorUvtController extends Controller
{
    public function __construct(protected ValorUvtService $valoresUvt) {}

    public function index(): JsonResponse
    {
        return response()->json(['valores_uvt' => $this->valoresUvt->listar()]);
    }

    public function store(GuardarValorUvtRequest $peticion): JsonResponse
    {
        $datos = $peticion->validated();

        return response()->json(['valor_uvt' => $this->valoresUvt->crear($datos['fecha_desde'], Dinero::desdePesos($datos['valor']))], 201);
    }

    public function update(EditarValorUvtRequest $peticion, ValorUvt $valorUvt): JsonResponse
    {
        $valorUvt = $this->valoresUvt->editar($valorUvt, Dinero::desdePesos($peticion->validated('valor')));

        return response()->json(['valor_uvt' => $valorUvt]);
    }

    public function destroy(ValorUvt $valorUvt): Response
    {
        $this->valoresUvt->eliminar($valorUvt);

        return response()->noContent();
    }
}
