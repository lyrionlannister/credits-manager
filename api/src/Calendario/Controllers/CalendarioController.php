<?php

namespace App\Calendario\Controllers;

use App\Calendario\Services\CalendarioService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CalendarioController extends Controller
{
    public function __construct(protected CalendarioService $calendario) {}

    public function index(Request $peticion): JsonResponse
    {
        $mes = $peticion->string('mes')->toString() ?: null;

        if ($mes && ! preg_match('/^\d{4}-\d{2}$/', $mes)) {
            throw ValidationException::withMessages(['mes' => __('validation.custom.mes.formato_invalido')]);
        }

        return response()->json($this->calendario->agenda($mes, $peticion->integer('empleador_id') ?: null));
    }
}
