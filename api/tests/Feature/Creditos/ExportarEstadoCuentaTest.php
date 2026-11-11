<?php

namespace Tests\Feature\Creditos;

use App\Creditos\Exportaciones\FuenteEstadoCuenta;
use App\Empresas\Models\Empresa;
use App\Exportacion\Constants\ExportacionConstants;
use App\Exportacion\Jobs\ProcesarExportacionJob;
use App\Exportacion\Models\Exportacion;
use App\Exportacion\Services\ExportacionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesAuditoriaFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

class ExportarEstadoCuentaTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesAuditoriaFixtures;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ExportacionConstants::DISCO);
        Queue::fake();
    }

    private function ruta(int $creditoId): string
    {
        return "/api/v1/creditos/{$creditoId}/estado-cuenta/exportar";
    }

    /** Ejecuta, una sola vez cada uno, los jobs encolados hasta ahora, como lo haría el worker. */
    private function ejecutarJobs(): void
    {
        Queue::assertPushed(ProcesarExportacionJob::class);

        Queue::pushed(ProcesarExportacionJob::class)->each(fn (ProcesarExportacionJob $job) => $job->handle(app(ExportacionService::class)));
    }

    /** @return list<list<mixed>> */
    private function leerDatos(Exportacion $exportacion): array
    {
        $lector = new Reader;
        $lector->open(Storage::disk(ExportacionConstants::DISCO)->path($exportacion->fresh()->ruta_archivo));
        $filas = [];

        foreach ($lector->getSheetIterator() as $hoja) {
            if ($hoja->getName() !== 'Datos') {
                continue;
            }

            foreach ($hoja->getRowIterator() as $fila) {
                $filas[] = $fila->toArray();
            }
        }

        $lector->close();

        return $filas;
    }

    public function test_sin_autenticar_recibe_401(): void
    {
        $this->postJson($this->ruta(1), ['formato' => 'xlsx'])->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_asesor_sin_permiso_de_exportar_recibe_403(): void
    {
        $this->actingAsAsesor();
        $credito = $this->crearCredito($this->crearCliente());

        $this->postJson($this->ruta($credito->id), ['formato' => 'xlsx'])->assertStatus(403);

        Queue::assertNothingPushed();
    }

    public function test_un_credito_de_otra_empresa_responde_404(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCredito($this->crearCliente());

        $this->actingAsAdmin();

        $this->postJson($this->ruta($credito->id), ['formato' => 'xlsx'])->assertStatus(404);

        Queue::assertNothingPushed();
    }

    public function test_el_asesor_no_exporta_el_estado_de_un_cliente_ajeno(): void
    {
        $admin = $this->actingAsAdmin();
        $credito = $this->crearCredito($this->crearCliente(['nombre' => 'Cliente del admin']));

        $this->actingAsRolConPermisos('asesor', ['creditos.exportar_estado_cuenta'], Empresa::find($admin->empresa_id));

        $this->postJson($this->ruta($credito->id), ['formato' => 'xlsx'])->assertStatus(404);

        Queue::assertNothingPushed();
    }

    public function test_valida_el_formato(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCredito($this->crearCliente());

        $this->postJson($this->ruta($credito->id), [])->assertStatus(422)->assertJsonValidationErrors('formato');
        $this->postJson($this->ruta($credito->id), ['formato' => 'pdf'])->assertStatus(422)->assertJsonValidationErrors('formato');

        Queue::assertNothingPushed();
    }

    public function test_exporta_el_plan_de_cuotas_con_lo_pagado_y_el_total(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCredito($this->crearCliente(['nombre' => 'Ana Gomez']));
        $this->crearCuota($credito, ['numero_cuota' => 1, 'fecha_vencimiento' => '2026-02-15', 'valor' => 300, 'valor_pagado' => 300, 'estado' => 'pagada']);
        $this->crearCuota($credito, ['numero_cuota' => 2, 'fecha_vencimiento' => '2026-03-15', 'valor' => 300]);

        $respuesta = $this->postJson($this->ruta($credito->id), ['formato' => 'xlsx'])->assertStatus(202);

        $this->assertDatabaseHas('exportaciones', ['id' => $respuesta->json('exportacion.id'), 'reporte' => 'creditos.estado_cuenta']);

        $this->ejecutarJobs();

        $datos = $this->leerDatos(Exportacion::find($respuesta->json('exportacion.id')));

        $this->assertCount(4, $datos, 'encabezado, dos cuotas y el total');
        $this->assertEquals(1, $datos[1][0]);
        $this->assertEquals(2, $datos[2][0]);
        // La columna 2 es la fecha real de pago, después del vencimiento.
        $this->assertSame('Fecha de pago', $datos[0][2]);
        $this->assertSame('Total', $datos[3][8]);
        $this->assertEquals(600, $datos[3][5]);
        $this->assertEquals(300, $datos[3][6]);
    }

    public function test_la_fuente_no_lee_un_credito_de_otra_empresa_aunque_no_haya_sesion(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCredito($this->crearCliente());
        $this->crearCuota($credito, ['numero_cuota' => 1, 'valor' => 300]);

        $otraEmpresa = $this->actingAsAdmin();

        $this->salirDeSesion();

        $this->expectException(ModelNotFoundException::class);

        iterator_to_array((new FuenteEstadoCuenta($otraEmpresa->empresa_id, $credito->id))->filas());
    }
}
