<?php

namespace Tests\Feature\Permisos;

use App\Empresas\Models\Empresa;
use App\Usuarios\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesAuditoriaFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\Concerns\CreatesFacturacionFixtures;
use Tests\TestCase;

/**
 * Pantallas que comparten endpoint y se separan por un parámetro o por el registro pedido: facturas y notas
 * (`tipo` del documento), cuentas por pagar y por cobrar (`naturaleza`) y libro mayor frente a caja y bancos
 * (clase de la cuenta). Cada una exige su permiso; el de la pantalla vecina no alcanza.
 */
class PermisosPorPantallaTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesAuditoriaFixtures;
    use CreatesCreditFixtures;
    use CreatesFacturacionFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/facturacion';

    private Empresa $empresa;

    private function prepararEmpresa(): void
    {
        $admin = $this->actingAsAdmin();
        $this->empresa = Empresa::findOrFail($admin->empresa_id);
    }

    private function actuarCon(array $permisos): Usuario
    {
        return $this->actingAsRolConPermisos('rol_'.uniqid(), $permisos, $this->empresa);
    }

    /** @return array{factura: array, nota_credito: array, nota_debito: array} creados como admin */
    private function documentos(): array
    {
        $this->crearReglasFacturacion();
        $credito = $this->crearCredito($this->crearCliente());

        $borrador = $this->postJson(self::BASE.'/documentos', [
            'cliente_id' => $credito->cliente_id,
            'credito_id' => $credito->id,
            'lineas' => [['concepto' => 'INTERES.AMORT.ADM', 'descripcion' => 'Interés administrativo', 'valor' => '1000', 'tipo_concepto' => 'otros_gastos']],
        ])->assertCreated()->json('documento');
        $factura = $this->postJson(self::BASE."/documentos/{$borrador['id']}/emitir")->assertOk()->json('documento');

        $notaCredito = $this->postJson(self::BASE."/documentos/{$factura['id']}/notas", [
            'tipo' => 'nota_credito', 'motivo_codigo' => 'ajuste_precio', 'lineas' => [['linea_origen_id' => $factura['lineas'][0]['id'], 'valor' => '200']],
        ])->assertCreated()->json('documento');
        $notaDebito = $this->postJson(self::BASE."/documentos/{$factura['id']}/notas", [
            'tipo' => 'nota_debito', 'motivo_codigo' => 'gastos_por_cobrar', 'fecha_vencimiento' => '2026-12-01',
            'lineas' => [['concepto' => 'MORA', 'descripcion' => 'Mora', 'valor' => '50']],
        ])->assertCreated()->json('documento');

        return ['factura' => $factura, 'nota_credito' => $notaCredito, 'nota_debito' => $notaDebito];
    }

    public function test_cada_tipo_de_documento_exige_su_permiso_de_lectura_en_el_listado_y_el_detalle(): void
    {
        $this->prepararEmpresa();
        $documentos = $this->documentos();

        foreach (['factura' => 'listar_facturas', 'nota_credito' => 'listar_notas_credito', 'nota_debito' => 'listar_notas_debito'] as $tipo => $accion) {
            $this->actuarCon(["facturacion.{$accion}"]);

            $this->getJson(self::BASE."/documentos?tipo={$tipo}")->assertOk()->assertJsonPath('data.0.id', $documentos[$tipo]['id']);
            $this->getJson(self::BASE."/documentos/{$documentos[$tipo]['id']}")->assertOk();

            foreach (array_diff(array_keys($documentos), [$tipo]) as $otro) {
                $this->getJson(self::BASE."/documentos?tipo={$otro}")->assertForbidden();
                $this->getJson(self::BASE."/documentos/{$documentos[$otro]['id']}")->assertForbidden();
            }
        }
    }

    public function test_el_listado_sin_tipo_devuelve_solo_los_tipos_que_el_usuario_puede_ver(): void
    {
        $this->prepararEmpresa();
        $documentos = $this->documentos();
        $this->actuarCon(['facturacion.listar_notas_credito']);

        $ids = collect($this->getJson(self::BASE.'/documentos')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$documentos['nota_credito']['id']], $ids);
    }

    public function test_imprimir_no_permite_descargar_un_tipo_que_no_puede_ver(): void
    {
        $this->prepararEmpresa();
        $documentos = $this->documentos();
        $this->actuarCon(['facturacion.listar_facturas', 'facturacion.imprimir']);

        $this->get(self::BASE."/documentos/{$documentos['nota_credito']['id']}/pdf")->assertForbidden();
        $this->getJson(self::BASE."/documentos/{$documentos['nota_credito']['id']}/envios")->assertForbidden();
        $this->getJson(self::BASE."/documentos/{$documentos['factura']['id']}/envios")->assertOk();
    }

    public function test_la_configuracion_de_facturacion_exige_configurar_y_no_un_permiso_de_lectura(): void
    {
        $this->prepararEmpresa();
        $this->actuarCon(['facturacion.listar_facturas', 'facturacion.listar_notas_credito', 'facturacion.listar_notas_debito']);

        $this->getJson(self::BASE.'/consecutivos')->assertForbidden();
        $this->getJson(self::BASE.'/parametrizacion-conceptos')->assertForbidden();

        $this->actuarCon(['facturacion.configurar']);
        $this->getJson(self::BASE.'/consecutivos')->assertOk();
    }

    public function test_cuentas_por_pagar_y_por_cobrar_se_leen_cada_una_con_su_permiso(): void
    {
        $this->prepararEmpresa();

        $this->actuarCon(['contabilidad.listar_cuentas_por_pagar']);
        $this->getJson('/api/v1/cartera-terceros?naturaleza=por_pagar')->assertOk();
        $this->getJson('/api/v1/cartera-terceros?naturaleza=anticipo_proveedor')->assertOk();
        $this->getJson('/api/v1/cartera-terceros?naturaleza=por_cobrar')->assertForbidden();
        $this->getJson('/api/v1/cartera-terceros/edades?naturaleza=por_cobrar')->assertForbidden();

        $this->actuarCon(['contabilidad.listar_cuentas_por_cobrar']);
        $this->getJson('/api/v1/cartera-terceros/edades?naturaleza=por_cobrar')->assertOk();
        $this->getJson('/api/v1/cartera-terceros?naturaleza=por_pagar')->assertForbidden();

        $this->actuarCon(['contabilidad.listar_comprobantes']);
        $this->getJson('/api/v1/cartera-terceros?naturaleza=por_pagar')->assertForbidden();
    }

    public function test_caja_y_bancos_solo_abre_el_mayor_de_cuentas_de_la_clase_11(): void
    {
        $this->prepararEmpresa();
        $caja = $this->crearCuenta(['codigo' => '110505', 'nombre' => 'Caja general']);
        $ingresos = $this->crearCuenta(['codigo' => '413505', 'nombre' => 'Intereses', 'naturaleza' => 'credito', 'tipo' => 'ingreso']);

        $this->actuarCon(['informes_contables.listar_caja_bancos']);
        $this->getJson("/api/v1/contabilidad/libro-mayor?cuenta_id={$caja->id}")->assertOk();
        $this->getJson("/api/v1/contabilidad/libro-mayor?cuenta_id={$ingresos->id}")->assertForbidden();
        $this->getJson('/api/v1/contabilidad/plan-cuentas')->assertOk();
        $this->getJson('/api/v1/contabilidad/balance-prueba')->assertForbidden();

        $this->actuarCon(['informes_contables.listar_libro_mayor']);
        $this->getJson("/api/v1/contabilidad/libro-mayor?cuenta_id={$ingresos->id}")->assertOk();
    }

    public function test_un_catalogo_compartido_no_permite_administrarlo(): void
    {
        $this->prepararEmpresa();
        $this->actuarCon(['gastos.crear']);

        $this->getJson('/api/v1/contabilidad/centros-costo')->assertOk();
        $this->getJson('/api/v1/gastos/tipos-gasto')->assertOk();
        $this->postJson('/api/v1/contabilidad/centros-costo', ['codigo' => 'X', 'nombre' => 'X'])->assertForbidden();
        $this->postJson('/api/v1/gastos/tipos-gasto', [])->assertForbidden();
        $this->getJson('/api/v1/contabilidad/plan-cuentas')->assertForbidden();
    }

    public function test_cada_pantalla_de_un_catalogo_tiene_su_propio_crud(): void
    {
        $this->prepararEmpresa();
        $this->actuarCon(['configuracion_contable.crear_centros_costo']);

        $this->postJson('/api/v1/contabilidad/centros-costo', ['codigo' => 'ADM', 'nombre' => 'Administración'])->assertCreated();
        $this->postJson('/api/v1/contabilidad/tipos-comprobante', [])->assertForbidden();
        $this->postJson('/api/v1/contabilidad/plan-cuentas', [])->assertForbidden();

        $this->actuarCon(['tributario.crear_valores_uvt']);
        $this->postJson('/api/v1/gastos/valores-uvt', ['fecha_desde' => '2031-01-01', 'valor' => '47065'])->assertCreated();
        $this->postJson('/api/v1/gastos/actividades-economicas', [])->assertForbidden();
        $this->getJson('/api/v1/gastos/valores-uvt')->assertForbidden();
    }

    public function test_el_dashboard_muestra_gastos_solo_con_listar_gastos(): void
    {
        $this->prepararEmpresa();

        $this->actuarCon(['gastos.listar_tipos_gasto']);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('metricas.gastos', null);

        $this->actuarCon(['gastos.listar_gastos']);
        $this->assertNotNull($this->getJson('/api/v1/dashboard')->assertOk()->json('metricas.gastos'));
    }
}
