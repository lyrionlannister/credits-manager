<?php

namespace Tests\Feature\Permisos;

use App\Usuarios\Constants\PermisoCacheConstants;
use App\Usuarios\Models\Permiso;
use App\Usuarios\Models\Rol;
use App\Usuarios\Models\Usuario;
use App\Usuarios\Services\EscuchaPermisos;
use App\Usuarios\Services\PermisoCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

/**
 * Fase 9: caché del permiso efectivo por (usuario, empresa) con invalidación por eventos.
 * M: el cambio se refleja sin esperar al TTL. N: el listener olvida la llave al leer el evento.
 * O: si Redis cae, se calcula contra la base de datos, nunca fail-open.
 */
class PermisoCacheTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    private Usuario $usuario;

    private Permiso $permiso;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->actingAsAdmin();
        $this->permiso = Permiso::create(['modulo' => 'clientes', 'accion' => 'crear']);
        $rol = Rol::create(['empresa_id' => $admin->empresa_id, 'nombre' => 'asesor_test_'.uniqid()]);
        $rol->permisos()->attach($this->permiso->id);

        $this->usuario = Usuario::create([
            'empresa_id' => $admin->empresa_id,
            'nombre' => 'Usuario de prueba',
            'usuario' => 'usuario_test_'.uniqid(),
            'password_hash' => 'irrelevante-en-tests',
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
    }

    public function test_el_permiso_se_cachea_y_la_invalidacion_lo_actualiza(): void
    {
        $this->assertTrue($this->usuario->tienePermiso('clientes', 'crear'));
        $this->assertNotNull(Cache::get(PermisoCacheConstants::llave((int) $this->usuario->id, $this->usuario->empresa_id)));

        // Cambio por fuera del servicio (sin invalidar): el caché manda hasta que se invalida.
        $this->usuario->rol->permisos()->detach($this->permiso->id);
        $this->assertTrue($this->usuario->fresh()->tienePermiso('clientes', 'crear'));

        app(PermisoCache::class)->invalidar($this->usuario->fresh());
        $this->assertFalse($this->usuario->fresh()->tienePermiso('clientes', 'crear'));
    }

    public function test_cambiar_los_permisos_del_rol_por_http_invalida_el_cache(): void
    {
        $this->assertTrue($this->usuario->tienePermiso('clientes', 'crear'));

        $this->patchJson("/api/v1/roles/{$this->usuario->rol_id}", [
            'nombre' => $this->usuario->rol->nombre, 'permisos' => [],
        ])->assertOk();

        $this->assertFalse($this->usuario->fresh()->tienePermiso('clientes', 'crear'));
    }

    public function test_el_listener_olvida_la_llave_al_leer_permissions_changed(): void
    {
        $this->assertTrue($this->usuario->tienePermiso('clientes', 'crear'));
        $this->usuario->rol->permisos()->detach($this->permiso->id);

        $this->redis->agregar(PermisoCacheConstants::STREAM, [
            'tipo' => 'permissions.changed',
            'usuario_id' => (string) $this->usuario->id,
            'empresa_id' => (string) $this->usuario->empresa_id,
            'version' => '2',
        ]);

        $procesadas = app(EscuchaPermisos::class)->ciclo('consumidor-prueba', 0);

        $this->assertSame(1, $procesadas);
        $this->assertNull(Cache::get(PermisoCacheConstants::llave((int) $this->usuario->id, $this->usuario->empresa_id)));
        $this->assertFalse($this->usuario->fresh()->tienePermiso('clientes', 'crear'));
    }

    public function test_si_redis_cae_el_permiso_se_calcula_contra_la_base_de_datos(): void
    {
        $this->redis->caido = true;

        // Invalidar no lanza aunque el evento no se pueda emitir…
        app(PermisoCache::class)->invalidar($this->usuario->fresh());

        // …y la decisión sigue saliendo de la base de datos.
        $this->assertTrue($this->usuario->fresh()->tienePermiso('clientes', 'crear'));
        $this->assertFalse($this->usuario->fresh()->tienePermiso('creditos', 'aprobar'));
    }
}
