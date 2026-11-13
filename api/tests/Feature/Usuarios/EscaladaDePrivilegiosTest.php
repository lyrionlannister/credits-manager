<?php

namespace Tests\Feature\Usuarios;

use App\Empresas\Models\Empresa;
use App\Usuarios\Models\Permiso;
use App\Usuarios\Models\Rol;
use App\Usuarios\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesAuditoriaFixtures;
use Tests\TestCase;

/**
 * `usuarios.crear`/`usuarios.editar` no pueden servir para saltarse que los roles sean de solo admin: quien no es
 * admin no concede (por rol o por excepción) lo que no tiene, ni crea, edita o elimina a un administrador.
 */
class EscaladaDePrivilegiosTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesAuditoriaFixtures;
    use RefreshDatabase;

    private const PERMISOS_GESTOR = ['usuarios.listar', 'usuarios.crear', 'usuarios.editar', 'usuarios.eliminar', 'clientes.listar'];

    private Empresa $empresa;

    private Rol $rolAdmin;

    private Usuario $gestor;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->actingAsAdmin();
        $this->empresa = Empresa::findOrFail($admin->empresa_id);
        $this->rolAdmin = $admin->rol;
        $this->gestor = $this->actingAsRolConPermisos('gestor', self::PERMISOS_GESTOR, $this->empresa);
    }

    private function rolCon(string $nombre, array $permisos): Rol
    {
        $rol = Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => $nombre]);
        $rol->permisos()->sync(collect($permisos)->map(fn (string $clave) => $this->permiso($clave)->id)->all());

        return $rol;
    }

    private function permiso(string $clave): Permiso
    {
        [$modulo, $accion] = explode('.', $clave);

        return Permiso::query()->firstOrCreate(['modulo' => $modulo, 'accion' => $accion]);
    }

    private function usuarioCon(Rol $rol): Usuario
    {
        return Usuario::create([
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Usuario '.uniqid(),
            'usuario' => 'usuario_'.uniqid(),
            'password_hash' => 'irrelevante-en-tests',
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
    }

    private function payload(Rol $rol, array $extra = []): array
    {
        return array_merge([
            'nombre' => 'Nuevo usuario',
            'usuario' => 'nuevo_'.uniqid(),
            'clave' => 'Clave123!',
            'rol_id' => $rol->id,
            'activo' => true,
        ], $extra);
    }

    public function test_no_admin_no_puede_crear_un_administrador(): void
    {
        $this->postJson('/api/v1/usuarios', $this->payload($this->rolAdmin))->assertForbidden();

        $this->assertDatabaseMissing('usuarios', ['nombre' => 'Nuevo usuario']);
    }

    public function test_no_admin_no_puede_asignar_un_rol_con_permisos_que_no_tiene(): void
    {
        $contable = $this->rolCon('contable', ['contabilidad.listar_comprobantes']);

        $this->postJson('/api/v1/usuarios', $this->payload($contable))->assertForbidden();
    }

    public function test_no_admin_no_puede_conceder_una_excepcion_que_no_tiene(): void
    {
        $lectura = $this->rolCon('lectura', ['clientes.listar']);
        $ajeno = $this->permiso('creditos.aprobar');

        $this->postJson('/api/v1/usuarios', $this->payload($lectura, [
            'permisos_extra' => [['permiso_id' => $ajeno->id, 'concedido' => true, 'motivo' => 'Motivo de prueba']],
        ]))->assertForbidden();
    }

    public function test_no_admin_puede_crear_usuarios_con_permisos_que_si_tiene_y_revocar_cualquiera(): void
    {
        $lectura = $this->rolCon('lectura', ['clientes.listar']);
        $ajeno = $this->permiso('creditos.aprobar');

        $this->postJson('/api/v1/usuarios', $this->payload($lectura, [
            'usuario' => 'creado_por_gestor',
            'permisos_extra' => [['permiso_id' => $ajeno->id, 'concedido' => false, 'motivo' => 'Motivo de prueba']],
        ]))->assertCreated();

        $this->assertDatabaseHas('usuarios', ['usuario' => 'creado_por_gestor', 'rol_id' => $lectura->id]);
    }

    public function test_no_admin_no_puede_darse_a_si_mismo_el_rol_admin(): void
    {
        $this->patchJson("/api/v1/usuarios/{$this->gestor->id}", [
            'nombre' => $this->gestor->nombre,
            'usuario' => $this->gestor->usuario,
            'rol_id' => $this->rolAdmin->id,
            'activo' => true,
        ])->assertForbidden();

        $this->assertDatabaseHas('usuarios', ['id' => $this->gestor->id, 'rol_id' => $this->gestor->rol_id]);
    }

    public function test_no_admin_no_puede_editar_ni_eliminar_a_un_administrador(): void
    {
        $otroAdmin = $this->usuarioCon($this->rolAdmin);

        $this->patchJson("/api/v1/usuarios/{$otroAdmin->id}", [
            'nombre' => 'Renombrado',
            'usuario' => $otroAdmin->usuario,
            'rol_id' => $this->rolAdmin->id,
            'activo' => false,
        ])->assertForbidden();
        $this->deleteJson("/api/v1/usuarios/{$otroAdmin->id}")->assertForbidden();

        $this->assertDatabaseHas('usuarios', ['id' => $otroAdmin->id, 'activo' => true, 'borrado_en' => null]);
    }

    public function test_editar_datos_sin_cambiar_el_rol_no_exige_tener_los_permisos_del_usuario(): void
    {
        $contable = $this->rolCon('contable', ['contabilidad.listar_comprobantes']);
        $usuario = $this->usuarioCon($contable);

        $this->patchJson("/api/v1/usuarios/{$usuario->id}", [
            'nombre' => 'Nombre corregido',
            'usuario' => $usuario->usuario,
            'rol_id' => $contable->id,
            'activo' => true,
        ])->assertOk();

        $this->assertDatabaseHas('usuarios', ['id' => $usuario->id, 'nombre' => 'Nombre corregido']);
    }

    public function test_el_admin_sigue_pudiendo_asignar_cualquier_rol_y_excepcion(): void
    {
        $this->actingAs(Usuario::query()->where('rol_id', $this->rolAdmin->id)->firstOrFail(), 'sanctum');
        $contable = $this->rolCon('contable', ['contabilidad.listar_comprobantes']);

        $this->postJson('/api/v1/usuarios', $this->payload($contable, [
            'permisos_extra' => [['permiso_id' => $this->permiso('creditos.aprobar')->id, 'concedido' => true, 'motivo' => 'Motivo de prueba']],
        ]))->assertCreated();
        $this->postJson('/api/v1/usuarios', $this->payload($this->rolAdmin))->assertCreated();
    }
}
