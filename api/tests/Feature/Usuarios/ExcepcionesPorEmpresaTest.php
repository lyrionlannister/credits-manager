<?php

namespace Tests\Feature\Usuarios;

use App\Usuarios\Models\Permiso;
use App\Usuarios\Models\Rol;
use App\Usuarios\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

/**
 * Las excepciones individuales son por empresa: una excepción puesta en la empresa A
 * nunca concede ni revoca en la empresa B, y sincronizar las de A no borra las de B.
 */
class ExcepcionesPorEmpresaTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    private function crearUsuarioConRol(array $permisosDelRol, ?int $empresaId = null): Usuario
    {
        $empresaId ??= $this->crearEmpresaDePrueba()->id;

        $rol = Rol::create(['empresa_id' => $empresaId, 'nombre' => 'asesor_test_'.uniqid()]);

        foreach ($permisosDelRol as [$modulo, $accion]) {
            $permiso = Permiso::create(['modulo' => $modulo, 'accion' => $accion]);
            $rol->permisos()->attach($permiso->id);
        }

        return Usuario::create([
            'empresa_id' => $empresaId,
            'nombre' => 'Usuario de prueba',
            'usuario' => 'usuario_test_'.uniqid(),
            'password_hash' => 'irrelevante-en-tests',
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
    }

    public function test_una_excepcion_de_otra_empresa_no_concede_ni_revoca(): void
    {
        $empresaA = $this->crearEmpresaDePrueba()->id;
        $empresaB = $this->crearEmpresaDePrueba()->id;
        $usuario = $this->crearUsuarioConRol([['clientes', 'crear']], $empresaA);
        $permiso = Permiso::where('modulo', 'clientes')->where('accion', 'crear')->firstOrFail();

        // Excepción que revoca, puesta en la empresa B (otra fila, otro alcance).
        DB::table('usuario_permiso')->insert([
            'usuario_id' => $usuario->id,
            'empresa_id' => $empresaB,
            'permiso_id' => $permiso->id,
            'concedido' => false,
        ]);

        // El rol de A sigue mandando: la revocación de B no se hereda.
        $this->assertTrue($usuario->fresh()->tienePermiso('clientes', 'crear'));

        // Y al revés: una concesión de B no concede en A.
        $otroPermiso = Permiso::create(['modulo' => 'contabilidad', 'accion' => 'listar']);
        DB::table('usuario_permiso')->insert([
            'usuario_id' => $usuario->id,
            'empresa_id' => $empresaB,
            'permiso_id' => $otroPermiso->id,
            'concedido' => true,
        ]);

        $this->assertFalse($usuario->fresh()->tienePermiso('contabilidad', 'listar'));
    }

    public function test_sincronizar_no_borra_las_excepciones_de_otra_empresa(): void
    {
        $empresaA = $this->crearEmpresaDePrueba()->id;
        $empresaB = $this->crearEmpresaDePrueba()->id;
        $usuario = $this->crearUsuarioConRol([], $empresaA);
        $permisoB = Permiso::create(['modulo' => 'contabilidad', 'accion' => 'listar']);

        DB::table('usuario_permiso')->insert([
            'usuario_id' => $usuario->id,
            'empresa_id' => $empresaB,
            'permiso_id' => $permisoB->id,
            'concedido' => true,
        ]);

        app(\App\Usuarios\Repositories\UsuarioRepository::class)->sincronizarPermisosExtra($usuario, []);

        $this->assertDatabaseHas('usuario_permiso', [
            'usuario_id' => $usuario->id,
            'empresa_id' => $empresaB,
            'permiso_id' => $permisoB->id,
            'concedido' => true,
        ]);
    }

    public function test_la_excepcion_guardada_lleva_la_empresa_del_usuario(): void
    {
        $usuario = $this->crearUsuarioConRol([]);
        $permiso = Permiso::create(['modulo' => 'clientes', 'accion' => 'crear']);

        app(\App\Usuarios\Repositories\UsuarioRepository::class)->sincronizarPermisosExtra($usuario, [
            $permiso->id => ['concedido' => true],
        ]);

        $this->assertDatabaseHas('usuario_permiso', [
            'usuario_id' => $usuario->id,
            'empresa_id' => $usuario->empresa_id,
            'permiso_id' => $permiso->id,
            'concedido' => true,
        ]);
        $this->assertTrue($usuario->fresh()->tienePermiso('clientes', 'crear'));
    }
}
