<?php

namespace App\Usuarios\Controllers;

use App\Http\Controllers\Controller;
use App\Usuarios\Models\Usuario;
use App\Usuarios\Services\DiagnosticoPermisoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Solo para administradores: explica por qué un usuario tiene o no tiene un permiso (Fase 0). */
class DiagnosticoPermisoController extends Controller
{
    public function __construct(protected DiagnosticoPermisoService $diagnostico) {}

    public function show(Request $peticion, Usuario $usuario): JsonResponse
    {
        $datos = $peticion->validate([
            'modulo' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'accion' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
        ]);

        return response()->json([
            'usuario' => ['id' => $usuario->id, 'usuario' => $usuario->usuario, 'empresa_id' => $usuario->empresa_id],
            'permiso' => "{$datos['modulo']}.{$datos['accion']}",
            'diagnostico' => $this->diagnostico->diagnosticar($usuario, $datos['modulo'], $datos['accion']),
        ]);
    }
}
