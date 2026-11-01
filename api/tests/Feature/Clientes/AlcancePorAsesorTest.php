<?php

namespace Tests\Feature\Clientes;

use App\Clientes\Models\Cliente;
use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Models\Credito;
use App\Empresas\Models\Empresa;
use App\Pagos\Enums\FormaPago;
use App\Pagos\Models\Pago;
use App\Usuarios\Models\Rol;
use App\Usuarios\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesAuditoriaFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

/** Un asesor sin alcance global solo ve los clientes asignados a él y, por ellos, sus créditos, pagos, cartera, calendario y dashboard. */
class AlcancePorAsesorTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesAuditoriaFixtures;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    private const PERMISOS = [
        'clientes.listar', 'clientes.ver', 'clientes.editar', 'clientes.crear',
        'creditos.listar', 'creditos.crear', 'creditos.editar', 'creditos.imprimir_estado_cuenta',
        'pagos.listar', 'pagos.crear', 'pagos.imprimir_recibo',
        'cartera.listar', 'cartera.imprimir_reporte_empleador', 'calendario.listar',
    ];

    private Usuario $asesorA;

    private Usuario $asesorB;

    private Cliente $clienteA;

    private Cliente $clienteB;

    private Credito $creditoA;

    private Credito $creditoB;

    private Pago $pagoA;

    private Pago $pagoB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asesorA = $this->actingAsRolConPermisos('asesor', self::PERMISOS);
        $rol = Rol::query()->firstOrFail();
        $this->asesorB = Usuario::create([
            'empresa_id' => $this->asesorA->empresa_id, 'nombre' => 'Asesor B', 'usuario' => 'asesor_b_'.uniqid(),
            'password_hash' => 'irrelevante-en-tests', 'rol_id' => $rol->id, 'activo' => true,
        ]);

        $this->clienteA = $this->crearCliente(['nombre' => 'Cliente de A', 'asesor_id' => $this->asesorA->id, 'numero_documento' => '1111']);
        $this->clienteB = $this->crearCliente(['nombre' => 'Cliente de B', 'asesor_id' => $this->asesorB->id, 'numero_documento' => '2222']);

        foreach (['A' => $this->clienteA, 'B' => $this->clienteB] as $letra => $cliente) {
            $credito = $this->crearCredito($cliente);
            $this->crearCuota($credito, ['numero_cuota' => 1, 'valor' => 300]);
            $pago = Pago::create([
                'credito_id' => $credito->id, 'cliente_id' => $cliente->id, 'valor_pago' => 100, 'fecha_pago' => '2026-02-01',
                'forma_pago' => FormaPago::Transferencia,
            ]);

            $this->{"credito{$letra}"} = $credito;
            $this->{"pago{$letra}"} = $pago;
        }
    }

    private function sinNombres(string $contenido, string $ajeno, string $propio): void
    {
        $this->assertStringContainsString($propio, $contenido);
        $this->assertStringNotContainsString($ajeno, $contenido);
    }

    public function test_el_listado_de_clientes_solo_trae_los_del_asesor(): void
    {
        $nombres = collect($this->getJson('/api/v1/clientes')->assertOk()->json('data'))->pluck('tercero.nombre');

        $this->assertEquals(['Cliente de A'], $nombres->all());
    }

    public function test_un_cliente_de_otro_asesor_responde_404_en_todas_sus_rutas(): void
    {
        $id = $this->clienteB->id;

        $this->getJson("/api/v1/clientes/{$id}")->assertStatus(404);
        $this->getJson("/api/v1/clientes/{$id}/resumen")->assertStatus(404);
        $this->getJson("/api/v1/clientes/{$id}/documentos")->assertStatus(404);
        $this->patchJson("/api/v1/clientes/{$id}", [])->assertStatus(404);
        $this->getJson("/api/v1/clientes/{$this->clienteA->id}")->assertOk();
    }

    public function test_los_creditos_de_otro_asesor_no_se_listan_ni_se_abren(): void
    {
        $ids = collect($this->getJson('/api/v1/creditos')->assertOk()->json('data'))->pluck('id');

        $this->assertEquals([$this->creditoA->id], $ids->all());
        $this->getJson("/api/v1/creditos/{$this->creditoB->id}")->assertStatus(404);
        $this->getJson("/api/v1/creditos/{$this->creditoB->id}/estado-cuenta")->assertStatus(404);
        $this->getJson("/api/v1/creditos/{$this->creditoB->id}/estado-cuenta/pdf")->assertStatus(404);
        $this->getJson("/api/v1/creditos/{$this->creditoB->id}/pagos")->assertStatus(404);
        $this->getJson("/api/v1/creditos/{$this->creditoA->id}")->assertOk();
    }

    public function test_los_pagos_y_recibos_de_otro_asesor_no_se_ven(): void
    {
        $ids = collect($this->getJson('/api/v1/pagos')->assertOk()->json('data'))->pluck('id');

        $this->assertEquals([$this->pagoA->id], $ids->all());
        $this->getJson("/api/v1/pagos/{$this->pagoB->id}/recibo/pdf")->assertStatus(404);
        $this->postJson("/api/v1/creditos/{$this->creditoB->id}/pagos", ['valor_pago' => 100, 'forma_pago' => 'efectivo'])->assertStatus(404);
    }

    public function test_la_cartera_solo_suma_los_creditos_del_asesor(): void
    {
        $respuesta = $this->getJson('/api/v1/cartera')->assertOk();

        $this->sinNombres($respuesta->getContent(), 'Cliente de B', 'Cliente de A');
    }

    public function test_el_calendario_solo_trae_las_cuotas_del_asesor(): void
    {
        $respuesta = $this->getJson('/api/v1/calendario?mes=2026-02')->assertOk();

        $this->sinNombres($respuesta->getContent(), 'Cliente de B', 'Cliente de A');
    }

    public function test_el_dashboard_solo_cuenta_lo_del_asesor(): void
    {
        $respuesta = $this->getJson('/api/v1/dashboard')->assertOk();

        $this->assertSame(1, $respuesta->json('metricas.clientes_activos'));
        $this->assertSame(1, $respuesta->json('metricas.creditos_activos'));
    }

    public function test_el_reporte_por_empleador_no_muestra_clientes_de_otro_asesor(): void
    {
        $respuesta = $this->getJson('/api/v1/cartera/reporte-empleador?empleador_id='.$this->clienteB->empleador_id)->assertOk();

        $this->assertStringNotContainsString('Cliente de B', $respuesta->getContent());
    }

    public function test_un_asesor_no_crea_creditos_para_clientes_de_otro_asesor(): void
    {
        $this->postJson('/api/v1/creditos', ['cliente_id' => $this->clienteB->id])
            ->assertStatus(422)->assertJsonValidationErrors('cliente_id');

        $this->postJson('/api/v1/creditos', ['cliente_id' => $this->clienteA->id, 'tipo_credito' => 'refinanciacion', 'credito_origen_id' => $this->creditoB->id])
            ->assertStatus(422)->assertJsonValidationErrors('credito_origen_id');
    }

    public function test_un_asesor_no_puede_duplicar_un_cliente_de_otro_asesor(): void
    {
        $this->postJson('/api/v1/clientes', [
            'nombres' => 'Otro', 'apellidos' => 'Nombre', 'tipo_documento' => 'cc', 'numero_documento' => '2222',
            'direccion' => 'Calle 1', 'ciudad' => 'Bogotá', 'celular' => '3001234567', 'correo' => 'otro@example.com',
            'empleador_id' => $this->clienteB->empleador_id,
        ])->assertStatus(422)->assertJsonValidationErrors('numero_documento');

        $this->assertDatabaseCount('clientes', 2);
    }

    public function test_leer_como_asesor_marca_la_mora_de_toda_la_empresa(): void
    {
        $this->getJson('/api/v1/cartera')->assertOk();

        $this->assertSame(EstadoCuota::Vencida, $this->creditoB->cuotas()->firstOrFail()->estado);
        $this->assertSame(EstadoCuota::Vencida, $this->creditoA->cuotas()->firstOrFail()->estado);
    }

    public function test_con_alcance_global_el_asesor_ve_todos_los_clientes_y_creditos(): void
    {
        $this->asesorA->update(['es_asesor_global' => true]);

        $this->assertCount(2, $this->getJson('/api/v1/clientes')->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/creditos')->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/pagos')->json('data'));
        $this->assertSame(2, $this->getJson('/api/v1/dashboard')->json('metricas.clientes_activos'));
        $this->getJson("/api/v1/clientes/{$this->clienteB->id}")->assertOk();
    }

    public function test_con_alcance_global_el_total_de_cartera_sigue_siendo_solo_del_asesor(): void
    {
        $this->asesorA->update(['es_asesor_global' => true]);

        $respuesta = $this->getJson('/api/v1/cartera')->assertOk();

        $this->sinNombres($respuesta->getContent(), 'Cliente de B', 'Cliente de A');
    }

    public function test_los_demas_roles_no_quedan_limitados_por_asesor(): void
    {
        $empresa = Empresa::findOrFail($this->asesorA->empresa_id);

        $this->actingAsRolConPermisos('contable', ['clientes.listar', 'clientes.ver', 'creditos.listar'], $empresa);
        $this->assertCount(2, $this->getJson('/api/v1/clientes')->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/creditos')->json('data'));

        $this->actingAsAdmin($empresa);
        $this->assertCount(2, $this->getJson('/api/v1/clientes')->json('data'));
        $this->assertSame(2, $this->getJson('/api/v1/dashboard')->json('metricas.clientes_activos'));
    }

    public function test_otra_empresa_no_ve_clientes_de_esta_aunque_tenga_el_mismo_asesor_global(): void
    {
        $otro = $this->actingAsRolConPermisos('asesor', self::PERMISOS);
        $otro->update(['es_asesor_global' => true]);

        $this->assertSame([], $this->getJson('/api/v1/clientes')->json('data'));
        $this->getJson("/api/v1/clientes/{$this->clienteA->id}")->assertStatus(404);
    }
}
