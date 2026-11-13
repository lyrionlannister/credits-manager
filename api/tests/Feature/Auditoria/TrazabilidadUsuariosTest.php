<?php

namespace Tests\Feature\Auditoria;

use App\Usuarios\Models\Permiso;
use App\Usuarios\Models\Rol;
use App\Usuarios\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\AfirmaAuditoria;
use Tests\TestCase;

class TrazabilidadUsuariosTest extends TestCase
{
    use ActsAsAdmin;
    use AfirmaAuditoria;
    use RefreshDatabase;

    private function permiso(string $modulo, string $accion): Permiso
    {
        return Permiso::query()->firstOrCreate(['modulo' => $modulo, 'accion' => $accion]);
    }

    private function usuarioExistente(Rol $rol, array $cambios = []): Usuario
    {
        return Usuario::create(array_merge([
            'nombre' => 'Usuario existente',
            'usuario' => 'existente_'.uniqid(),
            'password_hash' => 'irrelevante-en-tests',
            'rol_id' => $rol->id,
            'activo' => true,
        ], $cambios));
    }

    private function payloadEdicion(Usuario $usuario, array $cambios = []): array
    {
        return array_merge([
            'nombre' => $usuario->nombre,
            'usuario' => $usuario->usuario,
            'rol_id' => $usuario->rol_id,
            'activo' => true,
        ], $cambios);
    }

    public function test_crear_un_usuario_deja_sus_datos_y_permisos_extra_pero_nunca_la_clave(): void
    {
        $admin = $this->actingAsAdmin();
        $rol = Rol::create(['nombre' => 'asesor_test']);
        $permiso = $this->permiso('clientes', 'crear');

        $id = $this->postJson('/api/v1/usuarios', [
            'nombre' => 'Nuevo Asesor', 'usuario' => 'nuevo_asesor', 'clave' => 'Clave123!', 'rol_id' => $rol->id, 'activo' => true,
            'permisos_extra' => [['permiso_id' => $permiso->id, 'concedido' => true, 'motivo' => 'Motivo de prueba']],
        ])->assertCreated()->json('usuario.id');

        $evento = $this->assertEventoAuditoria('crear', 'usuarios', 'usuario', $id);
        $this->assertSame($admin->empresa_id, $evento['empresa_id']);
        $this->assertSame('nuevo_asesor', $evento['valores_nuevos']['usuario']);
        $this->assertSame(['clientes.crear' => true], $evento['valores_nuevos']['permisos_extra']);
        $this->assertStringNotContainsString('Clave123!', json_encode($evento));
        $this->assertArrayNotHasKey('password_hash', $evento['valores_nuevos']);
    }

    public function test_editar_el_nombre_deja_solo_lo_que_cambio_y_no_es_un_cambio_de_permisos(): void
    {
        $this->actingAsAdmin();
        $usuario = $this->usuarioExistente(Rol::create(['nombre' => 'asesor_test']), ['nombre' => 'Nombre viejo']);

        $this->patchJson("/api/v1/usuarios/{$usuario->id}", $this->payloadEdicion($usuario, ['nombre' => 'Nombre nuevo']))->assertOk();

        $evento = $this->assertEventoAuditoria('modificar', 'usuarios', 'usuario', $usuario->id);
        $this->assertSame(['nombre' => 'Nombre viejo'], $evento['valores_anteriores']);
        $this->assertSame(['nombre' => 'Nombre nuevo'], $evento['valores_nuevos']);
        $this->assertSinEventosDeAuditoria('cambio_permisos');
    }

    public function test_editar_sin_cambios_no_deja_evento(): void
    {
        $this->actingAsAdmin();
        $usuario = $this->usuarioExistente(Rol::create(['nombre' => 'asesor_test']));

        $this->patchJson("/api/v1/usuarios/{$usuario->id}", $this->payloadEdicion($usuario))->assertOk();

        $this->assertSinEventosDeAuditoria();
    }

    public function test_cambiar_el_rol_y_los_permisos_extra_deja_un_evento_de_cambio_de_permisos(): void
    {
        $this->actingAsAdmin();
        $rolAnterior = Rol::create(['nombre' => 'asesor_test']);
        $rolNuevo = Rol::create(['nombre' => 'contador_test']);
        $usuario = $this->usuarioExistente($rolAnterior);
        $permiso = $this->permiso('gastos', 'crear');

        $this->patchJson("/api/v1/usuarios/{$usuario->id}", $this->payloadEdicion($usuario, [
            'rol_id' => $rolNuevo->id,
            'permisos_extra' => [['permiso_id' => $permiso->id, 'concedido' => false, 'motivo' => 'Motivo de prueba']],
        ]))->assertOk();

        $evento = $this->assertEventoAuditoria('cambio_permisos', 'usuarios', 'usuario', $usuario->id);
        $this->assertSame(['rol_id' => $rolAnterior->id, 'permisos_extra' => []], $evento['valores_anteriores']);
        $this->assertSame(['rol_id' => $rolNuevo->id, 'permisos_extra' => ['gastos.crear' => false]], $evento['valores_nuevos']);
    }

    public function test_reenviar_los_mismos_permisos_extra_no_es_un_cambio_de_permisos(): void
    {
        $this->actingAsAdmin();
        $rol = Rol::create(['nombre' => 'asesor_test']);
        $usuario = $this->usuarioExistente($rol);
        $permiso = $this->permiso('gastos', 'crear');
        $payload = $this->payloadEdicion($usuario, ['permisos_extra' => [['permiso_id' => $permiso->id, 'concedido' => true, 'motivo' => 'Motivo de prueba']]]);

        $this->patchJson("/api/v1/usuarios/{$usuario->id}", $payload)->assertOk();
        $this->patchJson("/api/v1/usuarios/{$usuario->id}", [...$payload, 'nombre' => 'Otro nombre'])->assertOk();

        $this->assertCount(1, $this->eventosDeAuditoria('cambio_permisos'), 'solo la primera vez cambian los permisos');
    }

    public function test_desactivar_un_usuario_queda_en_el_evento_de_modificacion(): void
    {
        $this->actingAsAdmin();
        $usuario = $this->usuarioExistente(Rol::create(['nombre' => 'asesor_test']));

        $this->patchJson("/api/v1/usuarios/{$usuario->id}", $this->payloadEdicion($usuario, ['activo' => false]))->assertOk();

        $evento = $this->assertEventoAuditoria('modificar', 'usuarios', 'usuario', $usuario->id);
        $this->assertSame(['activo' => true], $evento['valores_anteriores']);
        $this->assertSame(['activo' => false], $evento['valores_nuevos']);
    }

    public function test_restablecer_la_clave_de_otro_usuario_deja_un_evento_sin_la_clave(): void
    {
        $this->actingAsAdmin();
        $usuario = $this->usuarioExistente(Rol::create(['nombre' => 'asesor_test']));

        $this->patchJson("/api/v1/usuarios/{$usuario->id}/clave", ['clave' => 'ClaveNueva123!'])->assertNoContent();

        $evento = $this->assertEventoAuditoria('modificar', 'usuarios', 'usuario', $usuario->id);
        $this->assertStringContainsString('restablecida', $evento['descripcion']);
        $this->assertNull($evento['valores_nuevos']);
        $this->assertStringNotContainsString('ClaveNueva123!', json_encode($evento));
    }

    public function test_cambiar_la_clave_propia_deja_un_evento_y_una_clave_incorrecta_no(): void
    {
        $usuario = $this->actingAsAdmin();

        $this->patchJson('/api/v1/me/clave', ['clave_actual' => 'incorrecta', 'clave_nueva' => 'ClaveNueva123!'])->assertStatus(422);
        $this->assertSinEventosDeAuditoria();

        $this->patchJson('/api/v1/me/clave', ['clave_actual' => 'irrelevante-en-tests', 'clave_nueva' => 'ClaveNueva123!'])->assertNoContent();

        $evento = $this->assertEventoAuditoria('modificar', 'auth', 'usuario', $usuario->id);
        $this->assertSame($usuario->id, $evento['autor']['usuario_id']);
        $this->assertStringNotContainsString('ClaveNueva123!', json_encode($evento));
    }

    public function test_eliminar_un_usuario_deja_un_evento(): void
    {
        $this->actingAsAdmin();
        $usuario = $this->usuarioExistente(Rol::create(['nombre' => 'asesor_test']));

        $this->deleteJson("/api/v1/usuarios/{$usuario->id}")->assertNoContent();

        $evento = $this->assertEventoAuditoria('eliminar', 'usuarios', 'usuario', $usuario->id);
        $this->assertSame($usuario->usuario, $evento['valores_anteriores']['usuario']);
    }

    public function test_crear_un_rol_deja_sus_permisos_y_editarlo_deja_el_antes_y_el_despues(): void
    {
        $this->actingAsAdmin();
        $crear = $this->permiso('clientes', 'crear');
        $editar = $this->permiso('clientes', 'editar');

        $id = $this->postJson('/api/v1/roles', ['nombre' => 'cobranza', 'permisos' => [$crear->id]])->assertCreated()->json('rol.id');
        $creacion = $this->assertEventoAuditoria('crear', 'usuarios', 'rol', $id);
        $this->assertSame(['clientes.crear'], $creacion['valores_nuevos']['permisos']);

        $this->patchJson("/api/v1/roles/{$id}", ['nombre' => 'cobranza', 'permisos' => [$crear->id, $editar->id]])->assertOk();

        $cambio = $this->assertEventoAuditoria('cambio_permisos', 'usuarios', 'rol', $id);
        $this->assertSame(['permisos' => ['clientes.crear']], $cambio['valores_anteriores']);
        $this->assertSame(['permisos' => ['clientes.crear', 'clientes.editar']], $cambio['valores_nuevos']);
        $this->assertSinEventosDeAuditoria('modificar', 'usuarios');
    }

    public function test_renombrar_un_rol_y_eliminarlo_dejan_evento(): void
    {
        $this->actingAsAdmin();
        $rol = Rol::create(['nombre' => 'cobranza']);

        $this->patchJson("/api/v1/roles/{$rol->id}", ['nombre' => 'cobranza_senior'])->assertOk();
        $this->deleteJson("/api/v1/roles/{$rol->id}")->assertNoContent();

        $renombre = $this->assertEventoAuditoria('modificar', 'usuarios', 'rol', $rol->id);
        $this->assertSame(['nombre' => 'cobranza'], $renombre['valores_anteriores']);
        $this->assertSame(['nombre' => 'cobranza_senior'], $renombre['valores_nuevos']);
        $this->assertEventoAuditoria('eliminar', 'usuarios', 'rol', $rol->id);
    }

    public function test_un_intento_rechazado_sobre_el_rol_admin_no_deja_evento(): void
    {
        $admin = $this->actingAsAdmin();

        $this->deleteJson("/api/v1/roles/{$admin->rol_id}")->assertStatus(422);

        $this->assertSinEventosDeAuditoria();
    }

    public function test_un_usuario_de_otra_empresa_no_se_puede_editar_ni_deja_evento(): void
    {
        $ajeno = $this->usuarioExistente(Rol::create(['nombre' => 'asesor_ajeno', 'empresa_id' => $this->crearEmpresaDePrueba()->id]), ['empresa_id' => $this->crearEmpresaDePrueba()->id]);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/usuarios/{$ajeno->id}", $this->payloadEdicion($ajeno, ['nombre' => 'Hackeado']))->assertNotFound();
        $this->deleteJson("/api/v1/usuarios/{$ajeno->id}")->assertNotFound();

        $this->assertSinEventosDeAuditoria();
    }
}
