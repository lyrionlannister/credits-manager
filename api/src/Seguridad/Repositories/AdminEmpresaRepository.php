<?php

namespace App\Seguridad\Repositories;

use App\Usuarios\Constants\UsuarioConstants;
use App\Usuarios\Models\Usuario;
use Illuminate\Support\Collection;

/**
 * Admins de empresa para el panel de seguridad del superadmin: una excepción de lectura/escritura
 * cruzada entre empresas (como `UsuarioAuditoriaRepository`), no la administración normal de usuarios
 * (que sigue acotada a su empresa en `UsuarioController`). El join a `roles` evita depender de la
 * relación `rol()`, que arrastraría su propio `EmpresaScope` dentro de un `whereHas`.
 */
class AdminEmpresaRepository
{
    /** @return Collection<int, Usuario> cada fila trae además `nombre_empresa` */
    public function listar(): Collection
    {
        return Usuario::query()
            ->withoutGlobalScopes()
            ->join('roles', 'roles.id', '=', 'usuarios.rol_id')
            ->join('empresas', 'empresas.id', '=', 'usuarios.empresa_id')
            ->where('roles.nombre', UsuarioConstants::ROL_ADMIN)
            ->whereNull('usuarios.borrado_en')
            ->orderBy('empresas.nombre_empresa')
            ->get(['usuarios.id', 'usuarios.nombre', 'usuarios.usuario', 'usuarios.activo', 'empresas.nombre_empresa']);
    }

    public function buscarOFallar(int $id): Usuario
    {
        return Usuario::query()
            ->withoutGlobalScopes()
            ->join('roles', 'roles.id', '=', 'usuarios.rol_id')
            ->where('roles.nombre', UsuarioConstants::ROL_ADMIN)
            ->where('usuarios.id', $id)
            ->select('usuarios.*')
            ->firstOrFail();
    }

    /** Clave temporal: el admin la debe cambiar en su próximo login (`AuthService::cambiarClavePropia`). */
    public function resetearClave(Usuario $admin, string $claveTemporal): void
    {
        $admin->fill(['password_hash' => $claveTemporal, 'debe_cambiar_clave' => true])->save();
    }
}
