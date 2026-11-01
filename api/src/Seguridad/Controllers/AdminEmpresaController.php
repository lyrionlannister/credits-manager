<?php

namespace App\Seguridad\Controllers;

use App\Auditoria\Enums\AccionAuditoria;
use App\Auditoria\Services\EmisorAuditoria;
use App\Http\Controllers\Controller;
use App\Seguridad\Repositories\AdminEmpresaRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/** Solo superadmin (middleware de ruta): recupera la clave de un admin de empresa con una temporal de un solo uso. */
class AdminEmpresaController extends Controller
{
    public function __construct(
        protected AdminEmpresaRepository $admins,
        protected EmisorAuditoria $auditoria,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['admins' => $this->admins->listar()]);
    }

    public function resetearClave(int $usuario): JsonResponse
    {
        $admin = $this->admins->buscarOFallar($usuario);
        $claveTemporal = Str::password(12);

        $this->admins->resetearClave($admin, $claveTemporal);

        $this->auditoria->registrar(
            AccionAuditoria::Modificar,
            'seguridad',
            "Clave de {$admin->usuario} restablecida por el superadmin",
            'usuario',
            $admin->id,
            empresaId: $admin->empresa_id,
        );

        return response()->json(['clave_temporal' => $claveTemporal]);
    }
}
