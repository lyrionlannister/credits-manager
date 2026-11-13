<?php

namespace Tests\Feature\Usuarios;

use App\Empresas\Models\Empresa;
use App\Usuarios\Models\Permiso;
use App\Usuarios\Models\Rol;
use App\Usuarios\Models\Usuario;
use App\Usuarios\Services\DiagnosticoPermisoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\AfirmaAuditoria;
use Tests\Concerns\CreatesAuditoriaFixtures;
use Tests\TestCase;

/** Fase 0: cada denegación lleva una causa visible y distinguible. Fase 1: el intento rechazado queda en bitácora. */
class DiagnosticoPermisoTest extends TestCase
{
    use ActsAsAdmin;
    use AfirmaAuditoria;
    use CreatesAuditoriaFixtures;
    use RefreshDatabase;

    private function crearUsuarioConRol(array $permisosDelRol, ?string $nombreRol = null, ?Empresa $empresa = null): Usuario
    {
        $empresa ??= $this->crearEmpresaDePrueba();

        $rol = Rol::create(['empresa_id' => $empresa->id, 'nombre' => $nombreRol ?? 'asesor_test_'.uniqid()]);

        foreach ($permisosDelRol as [$modulo, $accion]) {
            $permiso = Permiso::firstOrCreate(['modulo' => $modulo, 'accion' => $accion]);
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

    public function test_diagnostico_distingue_rol_excepcion_y_sin_permiso(): void
    {
        $usuario = $this->crearUsuarioConRol([['clientes', 'crear']]);
        $diagnostico = app(DiagnosticoPermisoService::class);

        $this->assertSame(DiagnosticoPermisoService::CAUSA_PERMISO_ROL,
            $diagnostico->diagnosticar($usuario, 'clientes', 'crear')['causa']);
        // El permiso existe en el catálogo (sembrado) pero ni el rol ni una excepción lo dan.
        Permiso::firstOrCreate(['modulo' => 'creditos', 'accion' => 'aprobar']);
        $this->assertSame(DiagnosticoPermisoService::CAUSA_SIN_PERMISO,
            $diagnostico->diagnosticar($usuario, 'creditos', 'aprobar')['causa']);
        $this->assertSame(DiagnosticoPermisoService::CAUSA_PERMISO_NO_CATALOGADO,
            $diagnostico->diagnosticar($usuario, 'modulo_inexistente', 'hacer_algo')['causa']);

        $permiso = Permiso::where('modulo', 'clientes')->where('accion', 'crear')->firstOrFail();
        $usuario->permisosExtra()->attach($permiso->id, [
            'concedido' => false, 'empresa_id' => $usuario->empresa_id, 'motivo' => 'Revocación', 'vence_en' => now()->addDays(10),
        ]);

        $resultado = $diagnostico->diagnosticar($usuario->fresh(), 'clientes', 'crear');
        $this->assertSame(DiagnosticoPermisoService::CAUSA_EXCEPCION_REVOCA, $resultado['causa']);
        $this->assertSame('Revocación', $resultado['excepcion']['motivo']);
    }

    public function test_diagnostico_del_admin_y_del_superadmin(): void
    {
        $admin = $this->actingAsAdmin();
        $diagnostico = app(DiagnosticoPermisoService::class);

        $this->assertSame(DiagnosticoPermisoService::CAUSA_BYPASS_ADMIN,
            $diagnostico->diagnosticar($admin, 'creditos', 'aprobar')['causa']);

        $superAdmin = $this->crearSuperAdmin();
        $resultado = $diagnostico->diagnosticar($superAdmin, 'clientes', 'listar');
        $this->assertSame(DiagnosticoPermisoService::CAUSA_SUPERADMIN_SIN_BYPASS, $resultado['causa']);
        $this->assertFalse($resultado['concedido']);
    }

    public function test_el_endpoint_de_diagnostico_responde_solo_a_admin_y_el_403_lleva_motivo(): void
    {
        $admin = $this->actingAsAdmin();
        $empresa = Empresa::findOrFail($admin->empresa_id);
        $usuario = $this->crearUsuarioConRol([['clientes', 'crear']], null, $empresa);
        // El permiso que protege el endpoint existe en el catálogo: la causa es no tenerlo, no su ausencia.
        Permiso::firstOrCreate(['modulo' => 'usuarios', 'accion' => 'listar']);

        $this->actingAsRolConPermisos('asesor_sin_usuarios', ['clientes.listar'], $empresa);
        $this->getJson("/api/v1/usuarios/{$usuario->id}/diagnostico-permiso?modulo=clientes&accion=crear")
            ->assertForbidden()
            ->assertJsonPath('motivo_denegacion', DiagnosticoPermisoService::CAUSA_SIN_PERMISO);

        $this->actingAs($admin, 'sanctum');
        $this->getJson("/api/v1/usuarios/{$usuario->id}/diagnostico-permiso?modulo=clientes&accion=crear")
            ->assertOk()
            ->assertJsonPath('diagnostico.causa', DiagnosticoPermisoService::CAUSA_PERMISO_ROL)
            ->assertJsonPath('diagnostico.concedido', true);
    }

    public function test_un_intento_rechazado_por_anti_escalada_queda_en_la_bitacora(): void
    {
        $admin = $this->actingAsAdmin();
        $empresa = Empresa::findOrFail($admin->empresa_id);
        $rolAsesor = Rol::create(['empresa_id' => $empresa->id, 'nombre' => 'asesor_test_'.uniqid()]);
        $ajeno = Permiso::create(['modulo' => 'creditos', 'accion' => 'aprobar']);

        // Gestor con usuarios.crear/editar pero sin creditos.aprobar: no puede concederlo ni por excepción.
        $this->actingAsRolConPermisos('gestor', ['usuarios.crear', 'usuarios.editar'], $empresa);

        $this->postJson('/api/v1/usuarios', [
            'nombre' => 'Intruso', 'usuario' => 'intruso_'.uniqid(), 'clave' => 'Clave123!',
            'rol_id' => $rolAsesor->id, 'activo' => true,
            'permisos_extra' => [['permiso_id' => $ajeno->id, 'concedido' => true, 'motivo' => 'Intento']],
        ])->assertForbidden();

        $evento = $this->assertEventoAuditoria('rechazar', 'usuarios', 'usuario');
        $this->assertContains('creditos.aprobar', $evento['valores_nuevos']['permisos_que_intentaba_conceder']);
    }
}
