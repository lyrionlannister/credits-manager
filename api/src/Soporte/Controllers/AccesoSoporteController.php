<?php

namespace App\Soporte\Controllers;

use App\Http\Controllers\Controller;
use App\Soporte\Models\AccesoSoporte;
use App\Soporte\Requests\SolicitarAccesoSoporteRequest;
use App\Soporte\Services\AccesoSoporteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccesoSoporteController extends Controller
{
    public function __construct(protected AccesoSoporteService $accesos) {}

    /** El superadmin ve sus solicitudes; un admin de empresa ve las de su empresa. */
    public function index(Request $peticion): JsonResponse
    {
        $usuario = $peticion->user();

        if (! $usuario->es_superadmin && $usuario->rol?->nombre !== 'admin') {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }

        $consulta = AccesoSoporte::query()->with(['empresa:id,nombre', 'superadmin:id,usuario']);

        if ($usuario->es_superadmin) {
            $consulta->where('superadmin_id', $usuario->id);
        } else {
            $consulta->where('empresa_id', $usuario->empresa_id);
        }

        return response()->json(['accesos' => $consulta->orderByDesc('solicitado_en')->get()]);
    }

    public function store(SolicitarAccesoSoporteRequest $peticion): JsonResponse
    {
        $datos = $peticion->validated();

        $acceso = $this->accesos->solicitar(
            $peticion->user(),
            (int) $datos['empresa_id'],
            $datos['motivo'],
            (int) ($datos['duracion_minutos'] ?? AccesoSoporteService::DURACION_POR_DEFECTO_MINUTOS),
        );

        return response()->json(['acceso' => $acceso], 201);
    }

    public function aprobar(Request $peticion, AccesoSoporte $acceso): JsonResponse
    {
        return response()->json(['acceso' => $this->accesos->aprobar($acceso, $peticion->user())]);
    }

    public function rechazar(Request $peticion, AccesoSoporte $acceso): JsonResponse
    {
        $datos = $peticion->validate(['motivo' => ['required', 'string', 'max:500']]);

        return response()->json(['acceso' => $this->accesos->rechazar($acceso, $peticion->user(), $datos['motivo'])]);
    }

    public function activar(Request $peticion, AccesoSoporte $acceso): JsonResponse
    {
        return response()->json(['acceso' => $this->accesos->activar($acceso, $peticion->user())]);
    }

    public function revocar(Request $peticion, AccesoSoporte $acceso): JsonResponse
    {
        return response()->json(['acceso' => $this->accesos->revocar($acceso, $peticion->user())]);
    }
}
