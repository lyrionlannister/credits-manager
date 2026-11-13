<?php

namespace Tests\Feature\Exportacion;

use App\Auditoria\Constants\AuditoriaConstants;
use App\Empresas\Models\Empresa;
use App\Exportacion\Constants\ExportacionConstants;
use App\Exportacion\Data\ColumnaExportable;
use App\Exportacion\Enums\EstadoExportacion;
use App\Exportacion\Enums\FormatoExportacion;
use App\Exportacion\Enums\TipoColumna;
use App\Exportacion\Models\Exportacion;
use App\Exportacion\Services\AccesoExportaciones;
use App\Exportacion\Services\ExportacionService;
use App\Notificaciones\Events\NotificacionCreada;
use App\Usuarios\Models\Permiso;
use App\Usuarios\Models\Usuario;
use Database\Seeders\PermisoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesAuditoriaFixtures;
use Tests\Support\FuenteExportableFalsa;
use Tests\TestCase;

class ExportacionesDelUsuarioTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesAuditoriaFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermisoSeeder::class);
        Storage::fake(ExportacionConstants::DISCO);
        $this->registrarReporteDePruebas();
    }

    /** La fuente falsa (`pruebas.reporte`) se autoriza, como cualquier reporte, por su entrada en el mapa de permisos. */
    private function registrarReporteDePruebas(): void
    {
        $this->app->instance(AccesoExportaciones::class, new AccesoExportaciones([
            ...ExportacionConstants::PERMISOS_POR_REPORTE,
            'pruebas.reporte' => ['pruebas.exportar'],
        ]));
    }

    private function fuente(bool $falla = false): FuenteExportableFalsa
    {
        return new FuenteExportableFalsa(
            [new ColumnaExportable('a', 'A', TipoColumna::Texto), new ColumnaExportable('m', 'Monto', TipoColumna::Monetario)],
            [['a' => 'uno', 'm' => '10.50'], ['a' => 'dos', 'm' => '20'], ['a' => 'tres', 'm' => '30']],
            fallaAlLeer: $falla,
        );
    }

    /** Solicita y procesa una exportación completa como el usuario autenticado. */
    private function exportacionCompleta(): Exportacion
    {
        $service = app(ExportacionService::class);
        $exportacion = $service->solicitar($this->fuente(), FormatoExportacion::Xlsx, auth()->user() ?? request()->user());
        $service->procesar($exportacion->id, $exportacion->empresa_id, $this->fuente(), FormatoExportacion::Xlsx);

        return $exportacion->fresh();
    }

    public function test_sin_autenticar_recibe_401(): void
    {
        $this->getJson('/api/v1/exportaciones')->assertStatus(401);
        $this->getJson('/api/v1/exportaciones/1/descargar')->assertStatus(401);
    }

    public function test_solicitar_exige_que_la_fuente_autorice_al_usuario(): void
    {
        $admin = $this->actingAsAdmin();
        $fuente = new FuenteExportableFalsa([new ColumnaExportable('a', 'A')], [['a' => 'x']], autorizada: false);

        $this->expectException(HttpException::class);

        app(ExportacionService::class)->solicitar($fuente, FormatoExportacion::Csv, $admin);
    }

    public function test_el_solicitante_descarga_su_archivo_y_la_descarga_queda_auditada(): void
    {
        $admin = $this->actingAsAdmin();
        $exportacion = $this->exportacionCompleta();
        $this->redis->streams = [];

        $respuesta = $this->get("/api/v1/exportaciones/{$exportacion->id}/descargar");

        $respuesta->assertOk()->assertDownload($exportacion->nombre_archivo);
        $this->assertSame(FormatoExportacion::Xlsx->tipoMime(), $respuesta->headers->get('Content-Type'));

        $evento = $this->redis->payloads(AuditoriaConstants::STREAM)[0];

        $this->assertSame('exportar', $evento['accion']);
        $this->assertSame($admin->id, $evento['autor']['usuario_id']);
        $this->assertSame($exportacion->id, $evento['entidad_id']);
        $this->assertStringContainsString('Descarga', $evento['descripcion']);
        $this->assertSame(3, $evento['valores_nuevos']['filas']);
    }

    public function test_el_archivo_descargado_es_un_xlsx_valido_con_los_datos(): void
    {
        $this->actingAsAdmin();
        $exportacion = $this->exportacionCompleta();

        $ruta = Storage::disk(ExportacionConstants::DISCO)->path($exportacion->ruta_archivo);
        $lector = new Reader;
        $lector->open($ruta);
        $filas = [];

        foreach ($lector->getSheetIterator() as $hoja) {
            if ($hoja->getName() === 'Datos') {
                foreach ($hoja->getRowIterator() as $fila) {
                    $filas[] = $fila->toArray();
                }
            }
        }

        $this->assertSame([['A', 'Monto'], ['uno', 10.5], ['dos', 20], ['tres', 30]], $filas);
    }

    public function test_otro_usuario_no_puede_descargar_el_archivo_ni_siquiera_de_su_misma_empresa(): void
    {
        $admin = $this->actingAsAdmin();
        $exportacion = $this->exportacionCompleta();

        $this->actingAsAsesor(Empresa::find($admin->empresa_id));

        $this->getJson("/api/v1/exportaciones/{$exportacion->id}/descargar")->assertStatus(404);
    }

    public function test_quien_perdio_el_permiso_del_reporte_ya_no_descarga_lo_que_habia_pedido(): void
    {
        $usuario = $this->actingAsRolConPermisos('exportador', ['pruebas.exportar']);
        $exportacion = $this->exportacionCompleta();
        $this->get("/api/v1/exportaciones/{$exportacion->id}/descargar")->assertOk();

        // Quitar el permiso por la vía real (PATCH /roles): el servicio invalida el caché de
        // permisos. Un detach directo sobre rol_permiso no pasa por esa invalidación y dejaría
        // el permiso cacheado hasta su TTL, así que no es una vía válida para probar esto.
        $this->actingAsAdmin(Empresa::find($usuario->empresa_id));
        $this->patchJson("/api/v1/roles/{$usuario->rol_id}", [
            'nombre' => $usuario->rol->nombre, 'permisos' => [],
        ])->assertOk();
        $this->redis->streams = [];

        $this->actingAs($usuario, 'sanctum');
        $this->getJson("/api/v1/exportaciones/{$exportacion->id}/descargar")->assertForbidden();
        $this->assertSame([], $this->redis->payloads(AuditoriaConstants::STREAM), 'Una descarga rechazada no se audita.');
    }

    public function test_un_permiso_de_otro_reporte_no_basta_para_descargar(): void
    {
        $usuario = $this->actingAsRolConPermisos('exportador', ['pruebas.exportar']);
        $exportacion = $this->exportacionCompleta();

        $usuario->rol->permisos()->sync([Permiso::query()->where(['modulo' => 'clientes', 'accion' => 'exportar_listado'])->value('id')]);

        $this->getJson("/api/v1/exportaciones/{$exportacion->id}/descargar")->assertForbidden();
    }

    public function test_un_reporte_sin_permiso_declarado_no_se_descarga_ni_siendo_admin(): void
    {
        $this->actingAsAdmin();
        $exportacion = $this->exportacionCompleta();

        $this->app->instance(AccesoExportaciones::class, new AccesoExportaciones);

        $this->getJson("/api/v1/exportaciones/{$exportacion->id}/descargar")->assertForbidden();
    }

    public function test_un_usuario_de_otra_empresa_recibe_404_al_descargar(): void
    {
        $this->actingAsAdmin();
        $exportacion = $this->exportacionCompleta();

        $this->actingAsAdmin();

        $this->getJson("/api/v1/exportaciones/{$exportacion->id}/descargar")->assertStatus(404);
    }

    public function test_una_exportacion_que_no_esta_completada_no_se_descarga(): void
    {
        $this->actingAsAdmin();
        $pendiente = app(ExportacionService::class)->solicitar($this->fuente(), FormatoExportacion::Csv, auth()->user());

        $this->getJson("/api/v1/exportaciones/{$pendiente->id}/descargar")->assertStatus(404);
    }

    public function test_si_el_archivo_ya_no_esta_en_el_volumen_da_404(): void
    {
        $this->actingAsAdmin();
        $exportacion = $this->exportacionCompleta();
        Storage::disk(ExportacionConstants::DISCO)->delete($exportacion->ruta_archivo);

        $this->getJson("/api/v1/exportaciones/{$exportacion->id}/descargar")->assertStatus(404);
    }

    public function test_lista_solo_mis_exportaciones_sin_exponer_la_ruta_del_archivo(): void
    {
        $this->actingAsAdmin();
        $mia = $this->exportacionCompleta();
        $this->actingAsAdmin();
        $this->exportacionCompleta();

        $this->actingAs(Usuario::query()->withoutGlobalScopes()->findOrFail($mia->usuario_id), 'sanctum');

        $respuesta = $this->getJson('/api/v1/exportaciones')->assertOk();

        $respuesta->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mia->id)->assertJsonMissingPath('data.0.ruta_archivo');
        $this->assertSame('completada', $respuesta->json('data.0.estado'));
    }

    public function test_una_falla_a_mitad_de_la_escritura_marca_fallida_borra_el_archivo_parcial_y_notifica(): void
    {
        Event::fake([NotificacionCreada::class]);
        $admin = $this->actingAsAdmin();
        $service = app(ExportacionService::class);
        $exportacion = $service->solicitar($this->fuente(), FormatoExportacion::Xlsx, $admin);

        $service->procesar($exportacion->id, $exportacion->empresa_id, $this->fuente(falla: true), FormatoExportacion::Xlsx);

        $exportacion->refresh();

        $this->assertSame(EstadoExportacion::Fallida, $exportacion->estado);
        $this->assertNull($exportacion->ruta_archivo);
        $this->assertSame('Falla a mitad de la lectura', $exportacion->error);
        $this->assertSame([], Storage::disk(ExportacionConstants::DISCO)->allFiles());
        $this->assertDatabaseHas('notificaciones', ['usuario_id' => $admin->id, 'tipo' => 'exportacion_fallida']);
        Event::assertDispatched(NotificacionCreada::class);
    }

    public function test_marcar_fallida_es_idempotente_no_notifica_dos_veces(): void
    {
        $admin = $this->actingAsAdmin();
        $service = app(ExportacionService::class);
        $exportacion = $service->solicitar($this->fuente(), FormatoExportacion::Csv, $admin);

        $service->marcarFallida($exportacion->id, $exportacion->empresa_id, 'Reporte', new \RuntimeException('timeout'));
        $service->marcarFallida($exportacion->id, $exportacion->empresa_id, 'Reporte', new \RuntimeException('timeout'));

        $this->assertDatabaseCount('notificaciones', 1);
    }

    public function test_el_worker_rechaza_procesar_una_exportacion_con_empresa_distinta_a_la_del_job(): void
    {
        $admin = $this->actingAsAdmin();
        $service = app(ExportacionService::class);
        $exportacion = $service->solicitar($this->fuente(), FormatoExportacion::Csv, $admin);
        $otra = $this->crearEmpresaDePrueba();

        $this->expectException(ModelNotFoundException::class);

        $service->procesar($exportacion->id, $otra->id, $this->fuente(), FormatoExportacion::Csv);
    }
}
