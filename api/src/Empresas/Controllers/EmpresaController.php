<?php

namespace App\Empresas\Controllers;

use App\Empresas\Enums\VarianteLogoEmpresa;
use App\Empresas\Requests\EditarEmpresaRequest;
use App\Empresas\Requests\SubirLogoEmpresaRequest;
use App\Empresas\Services\EmpresaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class EmpresaController extends Controller
{
    public function __construct(protected EmpresaService $empresas) {}

    public function show(): JsonResponse
    {
        return response()->json(['empresa' => $this->empresas->obtenerPropiaOpcional()]);
    }

    public function update(EditarEmpresaRequest $peticion): JsonResponse
    {
        $empresa = $this->empresas->editarEmpresa($this->empresas->obtenerPropia(), $peticion->validated());

        return response()->json(['empresa' => $empresa]);
    }

    public function uploadLogo(SubirLogoEmpresaRequest $peticion): JsonResponse
    {
        $empresa = $this->empresas->actualizarLogoPropia(
            $peticion->file('logo'),
            VarianteLogoEmpresa::from($peticion->validated('variante')),
        );

        return response()->json(['empresa' => $empresa]);
    }
}
