<?php

namespace Tests\Feature\Dashboard;

use App\Clientes\Models\Cliente;
use App\Creditos\Models\Credito;
use App\Empresas\Models\Empresa;
use App\Gastos\Enums\EstadoGasto;
use App\Gastos\Models\Gasto;
use App\Pagos\Enums\FormaPago;
use App\Pagos\Models\Pago;
use App\Usuarios\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesAuditoriaFixtures;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\Concerns\CreatesGastosFixtures;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesAuditoriaFixtures;
    use CreatesContabilidadFixtures;
    use CreatesCreditFixtures;
    use CreatesGastosFixtures;
    use RefreshDatabase;

    private const RUTA = '/api/v1/dashboard';

    private const PERMISOS_ASESOR = ['clientes.listar', 'creditos.listar', 'pagos.listar', 'cartera.listar'];

    public function test_sin_autenticar_recibe_401(): void
    {
        $this->getJson(self::RUTA)->assertStatus(401);
    }

    public function test_periodo_invalido_recibe_422(): void
    {
        $this->actingAsAdmin();

        $this->getJson(self::RUTA.'?periodo=2026-13')->assertStatus(422)->assertJsonValidationErrors('periodo');
        $this->getJson(self::RUTA.'?periodo=febrero')->assertStatus(422)->assertJsonValidationErrors('periodo');
    }

    public function test_estado_de_credito_invalido_recibe_422(): void
    {
        $this->actingAsAdmin();

        $this->getJson(self::RUTA.'?estado=inventado')->assertStatus(422)->assertJsonValidationErrors('estado');
    }

    public function test_admin_no_puede_elegir_otra_empresa(): void
    {
        $empresa = Empresa::findOrFail($this->actingAsAdmin()->empresa_id);

        $this->getJson(self::RUTA.'?empresa_id='.$empresa->id)->assertStatus(422)->assertJsonValidationErrors('empresa_id');
    }

    public function test_superadmin_debe_elegir_empresa(): void
    {
        $this->actingAsSuperAdmin();

        $this->getJson(self::RUTA)->assertStatus(422)->assertJsonValidationErrors('empresa_id');
    }

    public function test_superadmin_no_filtra_por_asesor_ni_cliente(): void
    {
        $this->actingAsSuperAdmin();
        $empresa = $this->crearEmpresaDePrueba();

        $this->getJson(self::RUTA.'?empresa_id='.$empresa->id.'&asesor_id=1&cliente_id=1')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asesor_id', 'cliente_id']);
    }

    public function test_asesor_de_otra_empresa_recibe_422(): void
    {
        $empresaA = $this->crearEmpresaDePrueba();
        $empresaB = $this->crearEmpresaDePrueba();
        $adminB = $this->actingAsAdmin($empresaB);

        $this->actingAsAdmin($empresaA);

        $this->getJson(self::RUTA.'?asesor_id='.$adminB->id)->assertStatus(422)->assertJsonValidationErrors('asesor_id');
    }

    public function test_cliente_de_otra_empresa_recibe_422(): void
    {
        $empresaA = $this->crearEmpresaDePrueba();
        $empresaB = $this->crearEmpresaDePrueba();

        $this->actingAsAdmin($empresaB);
        $clienteB = $this->crearCliente();

        $this->actingAsAdmin($empresaA);

        $this->getJson(self::RUTA.'?cliente_id='.$clienteB->id)->assertStatus(422)->assertJsonValidationErrors('cliente_id');
    }

    public function test_muestra_las_cifras_del_periodo_contrastadas_con_la_base(): void
    {
        $admin = $this->actingAsAdmin();
        $this->datosDeFebrero($admin->empresa_id);

        $respuesta = $this->getJson(self::RUTA.'?periodo=2026-02')->assertOk();

        $recaudoFebrero = DB::table('pagos')->where('empresa_id', $admin->empresa_id)
            ->where('fecha_pago', '>=', '2026-02-01')->where('fecha_pago', '<', '2026-03-01')->sum('valor_pago');
        $cartera = DB::table('cuotas')->join('creditos', 'creditos.id', '=', 'cuotas.credito_id')
            ->where('creditos.empresa_id', $admin->empresa_id)->whereIn('cuotas.estado', ['pendiente', 'vencida'])
            ->selectRaw('SUM(cuotas.valor - cuotas.valor_pagado) AS saldo')->value('saldo');

        $this->assertSame('250.00', $respuesta->json('metricas.recaudo.valor'));
        $this->assertSame(number_format($recaudoFebrero / 100, 2, '.', ''), $respuesta->json('metricas.recaudo.valor'));
        $this->assertSame(number_format($cartera / 100, 2, '.', ''), $respuesta->json('metricas.cartera.pendiente'));

        $this->assertSame('500.00', $respuesta->json('metricas.colocacion.valor'));
        $this->assertSame(1, $respuesta->json('metricas.colocacion.cantidad'));
        $this->assertSame('200.00', $respuesta->json('metricas.colocacion.mes_anterior.valor'));
        $this->assertEqualsWithDelta(150.0, $respuesta->json('metricas.colocacion.mes_anterior.variacion'), 0.001);
        $this->assertNull($respuesta->json('metricas.colocacion.anio_anterior.variacion'));

        $this->assertSame('100.00', $respuesta->json('metricas.recaudo.mes_anterior.valor'));
        $this->assertSame('30.00', $respuesta->json('metricas.gastos.valor'));

        $this->assertSame('400.00', $respuesta->json('metricas.cartera.al_dia'));
        $this->assertSame('300.00', $respuesta->json('metricas.cartera.vencida'));
        $this->assertSame(1, $respuesta->json('metricas.cartera.mora.creditos'));
        $this->assertSame('700.00', $respuesta->json('metricas.cartera.mora.saldo'));
        $this->assertSame('700.00', $respuesta->json('metricas.cartera.mora.promedio'));
        $this->assertSame(['1_30' => 0, '31_60' => 0, '61_90' => 0, 'mas_90' => 1], collect($respuesta->json('metricas.cartera.mora.rangos'))->pluck('creditos', 'clave')->all());

        $this->assertSame(1, $respuesta->json('metricas.clientes_activos'));
        $this->assertSame(2, $respuesta->json('metricas.creditos_activos'));

        $this->assertCount(1, $respuesta->json('pagos_hoy'));
        $this->assertSame('200.00', $respuesta->json('pagos_hoy.0.pendiente'));
        $this->assertSame('Ana Pérez', $respuesta->json('pagos_hoy.0.cliente_nombre'));

        $this->assertCount(12, $respuesta->json('historico.meses'));
        $this->assertSame('2026-02', $respuesta->json('historico.meses.11'));
        $this->assertSame('500.00', $respuesta->json('historico.colocacion.11'));
    }

    public function test_el_recaudo_no_cuenta_un_pago_reversado_en_su_mismo_mes(): void
    {
        $admin = $this->actingAsAdmin();
        $this->datosDeFebrero($admin->empresa_id);

        $pago = Pago::where('empresa_id', $admin->empresa_id)->where('fecha_pago', '>=', '2026-02-01')->where('fecha_pago', '<', '2026-03-01')->firstOrFail();
        DB::table('reversas_pago')->insert([
            'empresa_id' => $admin->empresa_id,
            'pago_id' => $pago->id,
            'fecha' => '2026-02-20',
            'valor' => $pago->valor_pago->centavos(),
            'motivo' => 'Cheque devuelto por el banco',
            'creado_en' => now(),
        ]);

        $esperado = number_format((25000 - $pago->valor_pago->centavos()) / 100, 2, '.', '');
        $this->getJson(self::RUTA.'?periodo=2026-02')->assertOk()->assertJsonPath('metricas.recaudo.valor', $esperado);
    }

    public function test_una_reversa_de_un_mes_posterior_no_saca_el_pago_de_su_mes(): void
    {
        $admin = $this->actingAsAdmin();
        $this->datosDeFebrero($admin->empresa_id);

        $pago = Pago::where('empresa_id', $admin->empresa_id)->where('fecha_pago', '>=', '2026-02-01')->where('fecha_pago', '<', '2026-03-01')->firstOrFail();
        DB::table('reversas_pago')->insert([
            'empresa_id' => $admin->empresa_id,
            'pago_id' => $pago->id,
            'fecha' => '2026-03-05',
            'valor' => $pago->valor_pago->centavos(),
            'motivo' => 'Cheque devuelto por el banco',
            'creado_en' => now(),
        ]);

        $this->getJson(self::RUTA.'?periodo=2026-02')->assertOk()->assertJsonPath('metricas.recaudo.valor', '250.00');
    }

    public function test_los_datos_de_otra_empresa_no_cuentan(): void
    {
        $empresaA = $this->crearEmpresaDePrueba();
        $empresaB = $this->crearEmpresaDePrueba();

        $this->actingAsAdmin($empresaB);
        $this->datosDeFebrero($empresaB->id);
        $this->crearCredito($this->crearCliente(), ['valor_prestamo' => 99999, 'fecha_desembolso' => '2026-02-10']);

        $this->actingAsAdmin($empresaA);
        $this->datosDeFebrero($empresaA->id);

        $this->getJson(self::RUTA.'?periodo=2026-02')
            ->assertOk()
            ->assertJsonPath('metricas.colocacion.valor', '500.00')
            ->assertJsonPath('metricas.recaudo.valor', '250.00');
    }

    public function test_superadmin_ve_la_empresa_elegida(): void
    {
        $empresa = $this->crearEmpresaDePrueba();
        $this->actuarComoAdmin($empresa);
        $this->datosDeFebrero($empresa->id);

        $this->actingAsSuperAdmin();

        $this->getJson(self::RUTA.'?periodo=2026-02&empresa_id='.$empresa->id)
            ->assertOk()
            ->assertJsonPath('metricas.colocacion.valor', '500.00');
    }

    public function test_asesor_solo_ve_su_cartera_y_no_el_total_de_la_empresa(): void
    {
        $admin = $this->actingAsAdmin();
        $empresa = Empresa::findOrFail($admin->empresa_id);
        $asesor = $this->actingAsRolConPermisos('asesor', self::PERMISOS_ASESOR, $empresa);

        $this->actuarComoAdmin($empresa);
        $clienteAdmin = $this->crearCliente(['asesor_id' => $admin->id]);
        $this->crearCuota($this->crearCredito($clienteAdmin, ['fecha_desembolso' => '2026-01-01']), ['valor' => 1000, 'fecha_vencimiento' => '2027-01-01']);
        $clienteAsesor = $this->crearCliente(['asesor_id' => $asesor->id]);
        $this->crearCuota($this->crearCredito($clienteAsesor, ['fecha_desembolso' => '2026-01-01']), ['valor' => 50, 'fecha_vencimiento' => '2027-01-01']);

        $totalEmpresa = DB::table('cuotas')->join('creditos', 'creditos.id', '=', 'cuotas.credito_id')
            ->where('creditos.empresa_id', $empresa->id)->whereIn('cuotas.estado', ['pendiente', 'vencida'])
            ->selectRaw('SUM(cuotas.valor - cuotas.valor_pagado) AS saldo')->value('saldo');

        $this->actingAs($asesor, 'sanctum');
        $respuesta = $this->getJson(self::RUTA)->assertOk();

        $this->assertSame('50.00', $respuesta->json('metricas.cartera.pendiente'));
        $this->assertNotSame(number_format($totalEmpresa / 100, 2, '.', ''), $respuesta->json('metricas.cartera.pendiente'));
        $this->assertSame(1, $respuesta->json('metricas.clientes_activos'));
        $this->assertNull($respuesta->json('metricas.gastos'));
    }

    public function test_asesor_no_puede_filtrar_por_otro_asesor(): void
    {
        $admin = $this->actingAsAdmin();
        $empresa = Empresa::findOrFail($admin->empresa_id);
        $asesor = $this->actingAsRolConPermisos('asesor', self::PERMISOS_ASESOR, $empresa);

        $this->actuarComoAdmin($empresa);
        $this->crearCliente(['asesor_id' => $admin->id]);

        $this->actingAs($asesor, 'sanctum');

        $this->getJson(self::RUTA.'?asesor_id='.$admin->id)
            ->assertOk()
            ->assertJsonPath('metricas.clientes_activos', 0);
    }

    public function test_sin_permiso_de_cartera_el_bloque_no_llega_y_no_es_403(): void
    {
        $empresa = $this->crearEmpresaDePrueba();
        $this->actingAsRolConPermisos('asesor', ['clientes.listar', 'creditos.listar'], $empresa);

        $this->getJson(self::RUTA)
            ->assertOk()
            ->assertJsonPath('metricas.cartera', null)
            ->assertJsonPath('metricas.gastos', null);
    }

    public function test_sin_permisos_de_cartera_clientes_ni_creditos_no_recibe_conteos_ni_cuotas_del_dia(): void
    {
        $empresa = $this->crearEmpresaDePrueba();
        $this->datosDeFebrero($empresa->id);
        $this->actingAsRolConPermisos('contable', ['gastos.listar_gastos', 'contabilidad.listar_comprobantes'], $empresa);

        $this->getJson(self::RUTA.'?periodo=2026-02')
            ->assertOk()
            ->assertJsonPath('metricas.clientes_activos', null)
            ->assertJsonPath('metricas.creditos_activos', null)
            ->assertJsonPath('pagos_hoy', null)
            ->assertJsonPath('metricas.gastos.valor', '30.00');
    }

    public function test_cada_bloque_del_dashboard_depende_solo_del_permiso_de_su_modulo(): void
    {
        $empresa = $this->crearEmpresaDePrueba();
        $this->datosDeFebrero($empresa->id);
        $this->actingAsRolConPermisos('consulta', ['clientes.listar'], $empresa);

        $respuesta = $this->getJson(self::RUTA.'?periodo=2026-02')->assertOk();

        $this->assertSame(1, $respuesta->json('metricas.clientes_activos'));
        $this->assertNull($respuesta->json('metricas.creditos_activos'));
        $this->assertNull($respuesta->json('pagos_hoy'));
    }

    /**
     * Datos de un caso conocido, creados como la empresa dada. Cifras (centavos):
     * - Colocación: 50.000 en febrero y 20.000 en enero.
     * - Recaudo: 10.000 en enero; 20.000 y 5.000 en febrero.
     * - Gastos: 3.000 causado en febrero y 9.000 anulado (no cuenta).
     * - Cuotas: 30.000 vencida (fecha pasada), 30.000 con 10.000 pagado que vence hoy, 20.000 al día.
     *
     * La cuota que vence hoy es de "Ana Pérez".
     */
    private function datosDeFebrero(int $empresaId): void
    {
        $empresa = Empresa::findOrFail($empresaId);
        $this->actuarComoAdmin($empresa);

        $ana = $this->crearCliente(['nombre' => 'Ana Pérez']);
        $creditoFebrero = $this->crearCredito($ana, ['valor_prestamo' => 500, 'fecha_desembolso' => '2026-02-10']);
        $this->crearCredito($ana, ['valor_prestamo' => 200, 'fecha_desembolso' => '2026-01-15']);

        $this->crearCuota($creditoFebrero, ['numero_cuota' => 1, 'valor' => 300, 'fecha_vencimiento' => '2026-01-05']);
        $this->crearCuota($creditoFebrero, ['numero_cuota' => 2, 'valor' => 300, 'valor_pagado' => 100, 'fecha_vencimiento' => now()->toDateString()]);
        $this->crearCuota($creditoFebrero, ['numero_cuota' => 3, 'valor' => 200, 'fecha_vencimiento' => '2027-12-01']);

        $this->pagar($creditoFebrero, $ana, 100, '2026-01-20 10:00:00');
        $this->pagar($creditoFebrero, $ana, 200, '2026-02-15 10:00:00');
        $this->pagar($creditoFebrero, $ana, 50, '2026-02-28 23:59:00');

        $tipo = $this->crearTipoGasto();
        $parametrizacion = $this->crearParametrizacion($tipo);
        $this->gasto($tipo->id, $parametrizacion->id, 30, EstadoGasto::Causado);
        $this->gasto($tipo->id, $parametrizacion->id, 90, EstadoGasto::Anulado);
    }

    /** `actingAsAdmin()` crea un rol `admin` nuevo; una empresa que ya lo tiene reutiliza a su admin. */
    private function actuarComoAdmin(Empresa $empresa): Usuario
    {
        $admin = Usuario::query()
            ->where('empresa_id', $empresa->id)
            ->whereHas('rol', fn ($rol) => $rol->where('nombre', 'admin'))
            ->first();

        if ($admin === null) {
            return $this->actingAsAdmin($empresa);
        }

        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    private function pagar(Credito $credito, Cliente $cliente, int $valor, string $fecha): void
    {
        Pago::create([
            'credito_id' => $credito->id,
            'cliente_id' => $cliente->id,
            'valor_pago' => $valor,
            'fecha_pago' => $fecha,
            'forma_pago' => FormaPago::Transferencia,
        ]);
    }

    private function gasto(int $tipoId, int $parametrizacionId, int $valor, EstadoGasto $estado): void
    {
        Gasto::create([
            'tipo_gasto_id' => $tipoId,
            'parametrizacion_tipo_gasto_id' => $parametrizacionId,
            'fecha' => '2026-02-10',
            'numero_documento' => 'FE-'.$valor,
            'concepto' => 'Gasto de prueba',
            'valor_bruto' => $valor,
            'valor_iva' => 0,
            'valor_retefuente' => 0,
            'valor_reteica' => 0,
            'valor_reteiva' => 0,
            'valor_neto' => $valor,
            'estado' => $estado,
            'ajuste_manual' => false,
        ]);
    }
}
