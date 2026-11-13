<?php

namespace Tests\Feature\Usuarios;

use App\Usuarios\Models\Permiso;
use App\Usuarios\Models\Rol;
use App\Usuarios\Models\Usuario;
use Database\Seeders\PermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\AfirmaAuditoria;
use Tests\TestCase;

/** Fase 10: cada cambio de permisos — edición, vencimiento y retiro del catálogo — queda en la bitácora. */
class BitacoraPermisosTest extends TestCase
{
    use ActsAsAdmin;
    use AfirmaAuditoria;
    use RefreshDatabase;

    public function test_editar_las_excepciones_de_un_usuario_queda_en_bitacora(): void
    {
        $admin = $this->actingAsAdmin();
        $rol = Rol::create(['empresa_id' => $admin->empresa_id, 'nombre' => 'asesor_test_'.uniqid()]);
        $permiso = Permiso::create(['modulo' => 'clientes', 'accion' => 'crear']);
        $usuario = Usuario::create([
            'empresa_id' => $admin->empresa_id, 'nombre' => 'Usuario', 'usuario' => 'usuario_'.uniqid(),
            'password_hash' => 'x', 'rol_id' => $rol->id, 'activo' => true,
        ]);

        $this->patchJson("/api/v1/usuarios/{$usuario->id}", [
            'nombre' => 'Usuario', 'usuario' => $usuario->usuario, 'rol_id' => $rol->id, 'activo' => true,
            'permisos_extra' => [['permiso_id' => $permiso->id, 'concedido' => true, 'motivo' => 'Apoyo temporal']],
        ])->assertOk();

        $evento = $this->assertEventoAuditoria('cambio_permisos', 'usuarios', 'usuario', (int) $usuario->id);
        $this->assertArrayHasKey('clientes.crear', $evento['valores_nuevos']['permisos_extra']);
    }

    public function test_vencer_una_excepcion_queda_en_bitacora(): void
    {
        $admin = $this->actingAsAdmin();
        $permiso = Permiso::create(['modulo' => 'clientes', 'accion' => 'crear']);
        $admin->permisosExtra()->attach($permiso->id, [
            'concedido' => true, 'empresa_id' => $admin->empresa_id,
            'motivo' => 'Temporal', 'vence_en' => now()->subDay(),
        ]);

        Artisan::call('permisos:vencer-excepciones');

        $evento = $this->assertEventoAuditoria('cambio_permisos', 'usuarios', 'usuario', (int) $admin->id);
        $this->assertEmpty($evento['valores_nuevos']);
    }

    public function test_retirar_un_permiso_del_catalogo_queda_en_bitacora(): void
    {
        $retirado = Permiso::create(['modulo' => 'modulo_viejo', 'accion' => 'hacer_algo']);

        $this->seed(PermisoSeeder::class);

        $this->assertSoftDeleted($retirado);
        $evento = $this->assertEventoAuditoria('cambio_permisos', 'usuarios', 'permiso', (int) $retirado->id);
        $this->assertArrayHasKey('modulo_viejo.hacer_algo', $evento['valores_anteriores']);
    }
}
