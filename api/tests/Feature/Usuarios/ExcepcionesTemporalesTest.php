<?php

namespace Tests\Feature\Usuarios;

use App\Usuarios\Models\Permiso;
use App\Usuarios\Models\Rol;
use App\Usuarios\Models\Usuario;
use App\Usuarios\Services\VencimientoExcepcionesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\AfirmaAuditoria;
use Tests\TestCase;

/** Excepciones individuales temporales: motivo obligatorio, vencimiento 30 días por defecto y máximo 90. */
class ExcepcionesTemporalesTest extends TestCase
{
    use ActsAsAdmin;
    use AfirmaAuditoria;
    use RefreshDatabase;

    private function crearUsuarioConRol(array $permisosDelRol): Usuario
    {
        $empresa = $this->crearEmpresaDePrueba();

        $rol = Rol::create(['empresa_id' => $empresa->id, 'nombre' => 'asesor_test_'.uniqid()]);

        foreach ($permisosDelRol as [$modulo, $accion]) {
            $permiso = Permiso::create(['modulo' => $modulo, 'accion' => $accion]);
            $rol->permisos()->attach($permiso->id);
        }

        return Usuario::create([
            'empresa_id' => $empresa->id,
            'nombre' => 'Usuario de prueba',
            'usuario' => 'usuario_test_'.uniqid(),
            'password_hash' => 'irrelevante-en-tests',
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
    }

    public function test_una_excepcion_vencida_deja_de_aplicar_y_el_rol_vuelve_a_mandar(): void
    {
        $usuario = $this->crearUsuarioConRol([['clientes', 'crear']]);
        $permiso = Permiso::where('modulo', 'clientes')->where('accion', 'crear')->firstOrFail();

        $usuario->permisosExtra()->attach($permiso->id, [
            'concedido' => false,
            'empresa_id' => $usuario->empresa_id,
            'motivo' => 'Revocación temporal',
            'vence_en' => now()->subDay(),
        ]);

        // La revocación venció: el rol vuelve a conceder.
        $this->assertTrue($usuario->fresh()->tienePermiso('clientes', 'crear'));

        // Y una concesión vencida deja de conceder.
        $otro = Permiso::create(['modulo' => 'contabilidad', 'accion' => 'listar']);
        $usuario->permisosExtra()->attach($otro->id, [
            'concedido' => true,
            'empresa_id' => $usuario->empresa_id,
            'motivo' => 'Concesión temporal',
            'vence_en' => now()->subDay(),
        ]);

        $this->assertFalse($usuario->fresh()->tienePermiso('contabilidad', 'listar'));
    }

    public function test_crear_usuario_sin_motivo_en_la_excepcion_es_422(): void
    {
        $admin = $this->actingAsAdmin();
        $permiso = Permiso::create(['modulo' => 'clientes', 'accion' => 'crear']);

        $this->postJson('/api/v1/usuarios', [
            'nombre' => 'Nuevo', 'usuario' => 'nuevo_'.uniqid(), 'clave' => 'Clave123!',
            'rol_id' => $admin->rol_id, 'activo' => true,
            'permisos_extra' => [['permiso_id' => $permiso->id, 'concedido' => true]],
        ])->assertStatus(422)->assertJsonValidationErrors('permisos_extra.0.motivo');
    }

    public function test_crear_usuario_con_vencimiento_mayor_a_90_dias_es_422_y_sin_vencimiento_vence_a_30(): void
    {
        $admin = $this->actingAsAdmin();
        $permiso = Permiso::create(['modulo' => 'clientes', 'accion' => 'crear']);

        $this->postJson('/api/v1/usuarios', [
            'nombre' => 'Nuevo', 'usuario' => 'nuevo_'.uniqid(), 'clave' => 'Clave123!',
            'rol_id' => $admin->rol_id, 'activo' => true,
            'permisos_extra' => [[
                'permiso_id' => $permiso->id, 'concedido' => true, 'motivo' => 'Cobertura',
                'vence_en' => now()->addDays(91)->toDateTimeString(),
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('permisos_extra.0.vence_en');

        $respuesta = $this->postJson('/api/v1/usuarios', [
            'nombre' => 'Nuevo', 'usuario' => 'nuevo_'.uniqid(), 'clave' => 'Clave123!',
            'rol_id' => $admin->rol_id, 'activo' => true,
            'permisos_extra' => [[
                'permiso_id' => $permiso->id, 'concedido' => true, 'motivo' => 'Cobertura',
            ]],
        ])->assertCreated();

        $usuario = Usuario::findOrFail($respuesta->json('usuario.id'));
        $pivote = $usuario->permisosExtra()->firstOrFail()->pivot;

        $this->assertSame('Cobertura', $pivote->motivo);
        $this->assertSame($admin->id, $pivote->responsable_id);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $pivote->vence_en->timestamp, 120);
    }

    public function test_vencer_borra_las_vencidas_deja_las_vigentes_y_lo_audita(): void
    {
        $usuario = $this->crearUsuarioConRol([]);
        $vencido = Permiso::create(['modulo' => 'clientes', 'accion' => 'crear']);
        $vigente = Permiso::create(['modulo' => 'contabilidad', 'accion' => 'listar']);

        $usuario->permisosExtra()->attach($vencido->id, [
            'concedido' => true, 'empresa_id' => $usuario->empresa_id, 'motivo' => 'Ya pasó', 'vence_en' => now()->subDay(),
        ]);
        $usuario->permisosExtra()->attach($vigente->id, [
            'concedido' => true, 'empresa_id' => $usuario->empresa_id, 'motivo' => 'Sigue', 'vence_en' => now()->addDays(10),
        ]);

        $vencidas = app(VencimientoExcepcionesService::class)->vencer();

        $this->assertSame(1, $vencidas);
        $this->assertDatabaseMissing('usuario_permiso', ['usuario_id' => $usuario->id, 'permiso_id' => $vencido->id]);
        $this->assertDatabaseHas('usuario_permiso', ['usuario_id' => $usuario->id, 'permiso_id' => $vigente->id]);

        $evento = $this->assertEventoAuditoria('cambio_permisos', 'usuarios', 'usuario', $usuario->id);
        $this->assertSame('Vencimiento automático de la excepción individual', $evento['motivo']);
    }
}
