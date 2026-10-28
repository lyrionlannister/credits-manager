<?php

namespace Tests\Feature\Auditoria;

use App\Terceros\Enums\TipoDocumentoTercero;
use App\Terceros\Services\DigitoVerificacionNit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\AfirmaAuditoria;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

class TrazabilidadMaestrosTest extends TestCase
{
    use ActsAsAdmin;
    use AfirmaAuditoria;
    use CreatesContabilidadFixtures;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    private function payloadCliente(int $empleadorId, array $cambios = []): array
    {
        return array_merge([
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'tipo_documento' => TipoDocumentoTercero::Cc->value,
            'numero_documento' => '1000000001',
            'direccion' => 'Calle 10 # 20-30',
            'ciudad' => 'Bogotá',
            'celular' => '3001234567',
            'correo' => 'juan@example.com',
            'empleador_id' => $empleadorId,
            'asesor_id' => auth()->id(),
        ], $cambios);
    }

    public function test_crear_un_cliente_deja_un_evento_con_los_datos_del_tercero(): void
    {
        $admin = $this->actingAsAdmin();
        $empleador = $this->crearEmpleador();

        $id = $this->postJson('/api/v1/clientes', $this->payloadCliente($empleador->id))->assertCreated()->json('cliente.id');

        $evento = $this->assertEventoAuditoria('crear', 'clientes', 'cliente', $id);
        $this->assertSame($admin->empresa_id, $evento['empresa_id']);
        $this->assertSame('Juan Pérez', $evento['valores_nuevos']['nombre']);
        $this->assertSame('1000000001', $evento['valores_nuevos']['numero_documento']);
        $this->assertSame($empleador->id, $evento['valores_nuevos']['empleador_id']);
        $this->assertFalse($evento['valores_nuevos']['tercero_reutilizado']);
    }

    public function test_editar_un_cliente_deja_solo_los_campos_que_cambiaron_incluido_el_empleador(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $tercero = $cliente->tercero;
        $otroEmpleador = $this->crearEmpleador();

        $this->patchJson("/api/v1/clientes/{$cliente->id}", $this->payloadCliente($otroEmpleador->id, [
            'nombres' => $tercero->nombres,
            'apellidos' => $tercero->apellidos,
            'numero_documento' => $tercero->numero_documento,
            'direccion' => $tercero->direccion,
            'ciudad' => $tercero->ciudad,
            'correo' => $tercero->correo,
            'celular' => '3119998888',
            'activo' => false,
        ]))->assertOk();

        $evento = $this->assertEventoAuditoria('modificar', 'clientes', 'cliente', $cliente->id);
        $this->assertEquals(['celular' => '3000000000', 'empleador_id' => $cliente->empleador_id, 'activo' => true], $evento['valores_anteriores']);
        $this->assertEquals(['celular' => '3119998888', 'empleador_id' => $otroEmpleador->id, 'activo' => false], $evento['valores_nuevos']);
    }

    public function test_eliminar_un_cliente_deja_un_evento(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();

        $this->deleteJson("/api/v1/clientes/{$cliente->id}")->assertNoContent();

        $this->assertEventoAuditoria('eliminar', 'clientes', 'cliente', $cliente->id);
    }

    public function test_un_cliente_con_creditos_activos_no_se_elimina_ni_deja_evento(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $this->crearCredito($cliente);

        $this->deleteJson("/api/v1/clientes/{$cliente->id}")->assertStatus(422);

        $this->assertSinEventosDeAuditoria();
    }

    public function test_subir_un_documento_al_cliente_deja_un_evento_sin_exponer_el_contenido(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();

        $id = $this->postJson("/api/v1/clientes/{$cliente->id}/documentos", ['archivo' => UploadedFile::fake()->create('cedula.pdf', 100, 'application/pdf')])
            ->assertCreated()->json('documento.id');

        $evento = $this->assertEventoAuditoria('crear', 'documentos', 'archivo_adjunto', $id);
        $this->assertSame('cedula.pdf', $evento['valores_nuevos']['nombre_original']);
        $this->assertSame($cliente->id, $evento['valores_nuevos']['adjuntable_id']);
        $this->assertStringContainsString("cliente #{$cliente->id}", $evento['descripcion']);
    }

    public function test_los_empleadores_dejan_evento_al_crearse_editarse_y_eliminarse(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/v1/empleadores', ['nombre' => 'Acme SAS', 'nit' => '900111222'])->assertCreated()->json('empleador.id');
        $this->patchJson("/api/v1/empleadores/{$id}", ['nombre' => 'Acme Colombia SAS', 'nit' => '900111222'])->assertOk();
        $this->deleteJson("/api/v1/empleadores/{$id}")->assertNoContent();

        $this->assertEventoAuditoria('crear', 'empleadores', 'empleador', $id);
        $edicion = $this->assertEventoAuditoria('modificar', 'empleadores', 'empleador', $id);
        $this->assertSame(['nombre' => 'Acme SAS'], $edicion['valores_anteriores']);
        $this->assertSame(['nombre' => 'Acme Colombia SAS'], $edicion['valores_nuevos']);
        $this->assertEventoAuditoria('eliminar', 'empleadores', 'empleador', $id);
    }

    public function test_un_empleador_con_clientes_no_se_elimina_ni_deja_evento(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();

        $this->deleteJson("/api/v1/empleadores/{$cliente->empleador_id}")->assertStatus(422);

        $this->assertSinEventosDeAuditoria();
    }

    public function test_crear_y_editar_un_tercero_dejan_evento(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/v1/terceros/personas-juridicas', [
            'numero_documento' => '900555666', 'digito_verificacion' => (string) DigitoVerificacionNit::calcular('900555666'),
            'razon_social' => 'Tercero uno', 'representante_legal' => 'Rep Uno',
        ])->assertCreated()->json('tercero.id');
        $creacion = $this->assertEventoAuditoria('crear', 'terceros', 'tercero', $id);
        $this->assertSame('otro', $creacion['valores_nuevos']['tipo']);
        $this->assertSame('900555666', $creacion['valores_nuevos']['numero_documento']);

        $this->patchJson("/api/v1/terceros/personas-juridicas/{$id}", ['razon_social' => 'Tercero dos'])->assertOk();

        $edicion = $this->assertEventoAuditoria('modificar', 'terceros', 'tercero', $id);
        $this->assertEquals(['razon_social' => 'Tercero uno', 'nombre' => 'Tercero uno'], $edicion['valores_anteriores']);
        $this->assertEquals(['razon_social' => 'Tercero dos', 'nombre' => 'Tercero dos'], $edicion['valores_nuevos']);
    }

    public function test_crear_y_editar_un_proveedor_dejan_evento_con_los_datos_del_tercero(): void
    {
        $this->actingAsAdmin();
        $payload = [
            'numero_documento' => '900123456', 'digito_verificacion' => '8', 'razon_social' => 'Proveedor SAS', 'representante_legal' => 'Rep Uno',
            'celular' => '3000000000', 'correo' => 'proveedor@example.com',
        ];

        $id = $this->postJson('/api/v1/proveedores/personas-juridicas', $payload)->assertCreated()->json('proveedor.id');
        $creacion = $this->assertEventoAuditoria('crear', 'proveedores', 'proveedor', $id);
        $this->assertSame('Proveedor SAS', $creacion['valores_nuevos']['nombre']);

        $this->patchJson("/api/v1/proveedores/personas-juridicas/{$id}", [...$payload, 'razon_social' => 'Proveedor Renombrado SAS', 'activo' => false])->assertOk();

        $edicion = $this->assertEventoAuditoria('modificar', 'proveedores', 'proveedor', $id);
        $this->assertEquals(['razon_social' => 'Proveedor SAS', 'nombre' => 'Proveedor SAS', 'activo' => true], $edicion['valores_anteriores']);
        $this->assertEquals(['razon_social' => 'Proveedor Renombrado SAS', 'nombre' => 'Proveedor Renombrado SAS', 'activo' => false], $edicion['valores_nuevos']);
    }

    public function test_cambiar_el_logo_de_la_empresa_deja_un_evento_con_la_ruta_anterior_y_la_nueva(): void
    {
        Storage::fake('public');
        $admin = $this->actingAsAdmin();

        $this->postJson('/api/v1/empresa/logo', ['logo' => UploadedFile::fake()->image('logo.png')])->assertOk();

        $evento = $this->assertEventoAuditoria('modificar', 'empresas', 'empresa', $admin->empresa_id);
        $this->assertNull($evento['valores_anteriores']['logo_path']);
        $this->assertStringStartsWith('storage/logos/', $evento['valores_nuevos']['logo_path']);
        $this->assertSame(['logo_path'], array_keys($evento['valores_nuevos']));
    }

    public function test_un_cliente_de_otra_empresa_no_se_edita_ni_deja_evento(): void
    {
        $this->actingAsAdmin($this->crearEmpresaDePrueba());
        $ajeno = $this->crearCliente();
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/clientes/{$ajeno->id}", $this->payloadCliente($this->crearEmpleador()->id))->assertNotFound();
        $this->deleteJson("/api/v1/clientes/{$ajeno->id}")->assertNotFound();

        $this->assertSinEventosDeAuditoria();
    }
}
