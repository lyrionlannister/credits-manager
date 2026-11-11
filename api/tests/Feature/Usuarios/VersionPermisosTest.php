<?php

namespace Tests\Feature\Usuarios;

use App\Usuarios\Models\Permiso;
use App\Usuarios\Models\Rol;
use App\Usuarios\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

/** Fase 3: cada cambio de permisos sube la versión del usuario y /me la devuelve sin caché. */
class VersionPermisosTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_me_devuelve_la_version_y_no_se_cachea(): void
    {
        $admin = $this->actingAsAdmin();

        $respuesta = $this->getJson('/api/v1/me')->assertOk();
        $respuesta->assertJsonPath('version_permisos', 1);
        // Symfony reordena la directiva y añade `private`: basta con que prohíba almacenar.
        $this->assertStringContainsString('no-store', $respuesta->headers->get('Cache-Control'));

        $this->assertSame(1, $admin->fresh()->version_permisos);
    }

    public function test_crear_y_editar_un_usuario_suben_su_version(): void
    {
        $admin = $this->actingAsAdmin();
        $permiso = Permiso::create(['modulo' => 'clientes', 'accion' => 'crear']);

        $respuesta = $this->postJson('/api/v1/usuarios', [
            'nombre' => 'Nuevo', 'usuario' => 'nuevo_'.uniqid(), 'clave' => 'Clave123!',
            'rol_id' => $admin->rol_id, 'activo' => true,
            'permisos_extra' => [['permiso_id' => $permiso->id, 'concedido' => true, 'motivo' => 'Apoyo']],
        ]);
        $respuesta->assertCreated();
        $this->assertSame(2, $respuesta->json('usuario.version_permisos'));

        $usuario = Usuario::findOrFail($respuesta->json('usuario.id'));
        $this->patchJson("/api/v1/usuarios/{$usuario->id}", [
            'nombre' => 'Nuevo editado', 'usuario' => $usuario->usuario,
            'rol_id' => $admin->rol_id, 'activo' => true, 'permisos_extra' => [],
        ])->assertOk();

        $this->assertSame(3, $usuario->fresh()->version_permisos);
    }

    public function test_cambiar_los_permisos_de_un_rol_sube_la_version_de_sus_usuarios(): void
    {
        $admin = $this->actingAsAdmin();
        $rol = Rol::findOrFail($admin->rol_id);
        $usuario = Usuario::where('rol_id', $rol->id)->firstOrFail();
        $rol->permisos()->attach(Permiso::create(['modulo' => 'clientes', 'accion' => 'listar'])->id);

        $this->patchJson("/api/v1/roles/{$rol->id}", [
            'nombre' => $rol->nombre, 'permisos' => [],
        ])->assertOk();

        $this->assertSame(2, $usuario->fresh()->version_permisos);
    }

    public function test_vencer_excepciones_sube_la_version_del_usuario(): void
    {
        $admin = $this->actingAsAdmin();
        $permiso = Permiso::create(['modulo' => 'clientes', 'accion' => 'crear']);
        $admin->permisosExtra()->attach($permiso->id, [
            'concedido' => true, 'empresa_id' => $admin->empresa_id,
            'motivo' => 'Temporal', 'vence_en' => now()->subDay(),
        ]);
        $admin->increment('version_permisos');

        $this->assertSame(2, $admin->fresh()->version_permisos);

        Artisan::call('permisos:vencer-excepciones');

        $this->assertSame(0, $admin->fresh()->permisosExtra()->count());
        $this->assertSame(3, $admin->fresh()->version_permisos);
    }
}
