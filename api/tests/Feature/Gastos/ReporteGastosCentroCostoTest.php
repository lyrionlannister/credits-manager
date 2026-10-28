<?php

namespace Tests\Feature\Gastos;

use App\Causacion\Models\TipoGasto;
use App\Causacion\Models\ValorUvt;
use App\Contabilidad\Models\CentroCosto;
use App\Empresas\Models\Empresa;
use App\Exportacion\Constants\ExportacionConstants;
use App\Exportacion\Jobs\ProcesarExportacionJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesAuditoriaFixtures;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesGastosFixtures;
use Tests\TestCase;

/** Gastos mensuales por centro de costo: el centro es obligatorio, el reporte suma causados y excluye anulados. */
class ReporteGastosCentroCostoTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesAuditoriaFixtures;
    use CreatesContabilidadFixtures;
    use CreatesGastosFixtures;
    use RefreshDatabase;

    private const REPORTE = '/api/v1/gastos/reporte-centro-costo';

    /** Un gestor de gastos de la empresa, con la parametrización de un tipo de honorarios lista para causar. */
    private function prepararEmpresa(Empresa $empresa): TipoGasto
    {
        $this->actingAsRolConPermisos('gestor', ['gastos.listar_gastos', 'gastos.listar_gastos_centro_costo', 'gastos.crear', 'gastos.anular', 'gastos.exportar_gastos_centro_costo'], $empresa);
        ValorUvt::query()->firstOrCreate(['fecha_desde' => '2026-01-01'], ['valor' => 47065]);
        $tipo = $this->crearTipoGasto(['requiere_tercero' => false, 'aplica_iva' => false]);
        $this->crearParametrizacion($tipo, ['tarifa_iva' => 0, 'aplica_retefuente' => false, 'aplica_reteica' => false, 'aplica_reteiva' => false]);

        return $tipo;
    }

    private function causar(TipoGasto $tipo, CentroCosto $centro, string $fecha, int $valor): int
    {
        Storage::fake('public');

        return $this->postJson('/api/v1/gastos', [
            'tipo_gasto_id' => $tipo->id,
            'centro_costo_id' => $centro->id,
            'fecha' => $fecha,
            'numero_documento' => 'DOC-'.uniqid(),
            'concepto' => 'Gasto de prueba',
            'valor_bruto' => $valor,
            'soporte' => UploadedFile::fake()->create('soporte.pdf', 200, 'application/pdf'),
        ])->assertCreated()->json('gasto.id');
    }

    public function test_sin_autenticar_no_consulta_el_reporte(): void
    {
        $this->getJson(self::REPORTE.'?desde=2026-03&hasta=2026-04')->assertStatus(401);
    }

    public function test_sin_permiso_de_gastos_no_consulta_el_reporte(): void
    {
        $empresa = $this->crearEmpresaDePrueba();
        $this->actingAsAsesor($empresa);

        $this->getJson(self::REPORTE.'?desde=2026-03&hasta=2026-04')->assertStatus(403);
    }

    public function test_exige_meses_en_orden(): void
    {
        $this->actingAsAdmin();

        $this->getJson(self::REPORTE.'?desde=2026-05&hasta=2026-03')
            ->assertStatus(422)
            ->assertJsonValidationErrors('hasta');
    }

    public function test_un_gasto_sin_centro_de_costo_se_rechaza(): void
    {
        $empresa = $this->crearEmpresaDePrueba();
        $tipo = $this->prepararEmpresa($empresa);

        $this->postJson('/api/v1/gastos', [
            'tipo_gasto_id' => $tipo->id,
            'fecha' => '2026-03-01',
            'numero_documento' => 'FAC-SIN-CC',
            'concepto' => 'Sin centro',
            'valor_bruto' => 100000,
        ])->assertStatus(422)->assertJsonValidationErrors('centro_costo_id');
    }

    public function test_un_centro_de_otra_empresa_no_se_acepta_en_un_gasto(): void
    {
        $empresa = $this->crearEmpresaDePrueba();
        $otra = $this->crearEmpresaDePrueba();
        $this->actingAsAdmin($otra);
        $centroAjeno = $this->crearCentroCosto();
        $tipo = $this->prepararEmpresa($empresa);

        $this->postJson('/api/v1/gastos', [
            'tipo_gasto_id' => $tipo->id,
            'centro_costo_id' => $centroAjeno->id,
            'fecha' => '2026-03-01',
            'numero_documento' => 'FAC-AJENO',
            'concepto' => 'Centro ajeno',
            'valor_bruto' => 100000,
        ])->assertStatus(422)->assertJsonValidationErrors('centro_costo_id');
    }

    public function test_suma_los_gastos_causados_por_mes_y_centro(): void
    {
        $empresa = $this->crearEmpresaDePrueba();
        $tipo = $this->prepararEmpresa($empresa);
        $administracion = $this->crearCentroCosto(['codigo' => 'ADM', 'nombre' => 'Administración']);
        $ventas = $this->crearCentroCosto(['codigo' => 'VEN', 'nombre' => 'Ventas']);

        $this->causar($tipo, $administracion, '2026-03-01', 1000000);
        $this->causar($tipo, $ventas, '2026-03-15', 500000);
        $this->causar($tipo, $administracion, '2026-04-02', 200000);

        $respuesta = $this->getJson(self::REPORTE.'?desde=2026-03&hasta=2026-04')->assertOk();

        $this->assertCount(3, $respuesta->json('filas'));
        $this->assertSame(3, $respuesta->json('total_cantidad'));
        $this->assertSame('1700000.00', $respuesta->json('total_bruto'));

        $marzoAdministracion = collect($respuesta->json('filas'))->first(
            fn (array $fila) => $fila['mes'] === '2026-03' && $fila['centro_costo']['codigo'] === 'ADM'
        );
        $this->assertSame('1000000.00', $marzoAdministracion['valor_bruto']);
        $this->assertSame(1, $marzoAdministracion['cantidad']);
    }

    public function test_un_gasto_anulado_no_entra_al_reporte(): void
    {
        $empresa = $this->crearEmpresaDePrueba();
        $tipo = $this->prepararEmpresa($empresa);
        $centro = $this->crearCentroCosto();
        $this->causar($tipo, $centro, '2026-03-01', 1000000);
        $anulado = $this->causar($tipo, $centro, '2026-03-02', 300000);

        $this->deleteJson("/api/v1/gastos/{$anulado}")->assertNoContent();

        $this->getJson(self::REPORTE.'?desde=2026-03&hasta=2026-03')
            ->assertOk()
            ->assertJsonPath('total_bruto', '1000000.00')
            ->assertJsonPath('total_cantidad', 1);
    }

    public function test_el_reporte_no_incluye_los_gastos_de_otra_empresa(): void
    {
        $empresa = $this->crearEmpresaDePrueba();
        $otra = $this->crearEmpresaDePrueba();
        $tipoOtra = $this->prepararEmpresa($otra);
        $this->causar($tipoOtra, $this->crearCentroCosto(), '2026-03-01', 900000);

        $this->prepararEmpresa($empresa);

        $this->getJson(self::REPORTE.'?desde=2026-03&hasta=2026-03')
            ->assertOk()
            ->assertJsonPath('total_cantidad', 0)
            ->assertJsonPath('total_bruto', '0.00');
    }

    public function test_exporta_el_reporte_en_segundo_plano(): void
    {
        Storage::fake(ExportacionConstants::DISCO);
        Queue::fake();
        $empresa = $this->crearEmpresaDePrueba();
        $this->prepararEmpresa($empresa);

        $this->postJson(self::REPORTE.'/exportar', ['desde' => '2026-03', 'hasta' => '2026-04', 'formato' => 'csv'])
            ->assertStatus(202);

        Queue::assertPushed(ProcesarExportacionJob::class);
    }

    public function test_exportar_sin_permiso_de_exportacion_responde_403(): void
    {
        $empresa = $this->crearEmpresaDePrueba();
        $this->actingAsRolConPermisos('solo_listar_gastos', ['gastos.listar_gastos_centro_costo'], $empresa);

        $this->postJson(self::REPORTE.'/exportar', ['desde' => '2026-03', 'hasta' => '2026-04', 'formato' => 'csv'])
            ->assertStatus(403);
    }
}
