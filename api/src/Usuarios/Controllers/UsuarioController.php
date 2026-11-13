<?php

namespace App\Usuarios\Controllers;

use App\Http\Controllers\Controller;
use App\Usuarios\Models\Usuario;
use App\Usuarios\Requests\CambiarClaveRequest;
use App\Usuarios\Requests\CrearUsuarioRequest;
use App\Usuarios\Requests\EditarUsuarioRequest;
use App\Usuarios\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UsuarioController extends Controller
{
    public function __construct(protected UsuarioService $usuarios) {}

    public function index(Request $peticion): JsonResponse
    {
        $usuarios = $this->usuarios->listarUsuarios(
            busqueda: $peticion->string('search')->toString(),
            columna: $peticion->string('sort', 'nombre')->toString(),
            direccion: $peticion->string('direccion', 'asc')->toString(),
            porPagina: $peticion->integer('per_page', 10),
            pagina: $peticion->integer('page', 1),
        );

        return response()->json($usuarios);
    }

    public function store(CrearUsuarioRequest $peticion): JsonResponse
    {
        $usuario = $this->usuarios->crearUsuario($peticion->validated());

        return response()->json(['usuario' => $usuario->load(['rol', 'permisosExtra' => Usuario::soloVigentes()])], 201);
    }

    public function show(Usuario $usuario): JsonResponse
    {
        return response()->json(['usuario' => $usuario->load(['rol', 'permisosExtra'])]);
    }

    public function update(EditarUsuarioRequest $peticion, Usuario $usuario): JsonResponse
    {
        $usuario = $this->usuarios->editarUsuario($usuario, $peticion->validated());

        return response()->json(['usuario' => $usuario->load(['rol', 'permisosExtra' => Usuario::soloVigentes()])]);
    }

    public function cambiarClave(CambiarClaveRequest $peticion, Usuario $usuario): Response
    {
        $this->usuarios->cambiarClave($usuario, $peticion->validated()['clave']);

        return response()->noContent();
    }

    public function destroy(Usuario $usuario): Response
    {
        $this->usuarios->eliminarUsuario($usuario);

        return response()->noContent();
    }
}
