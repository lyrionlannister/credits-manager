<?php

namespace Tests\Feature\Auditoria;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\AfirmaAuditoria;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesGastosFixtures;
use Tests\TestCase;

class TrazabilidadGastosTest extends TestCase
{
    use ActsAsAdmin;
    use AfirmaAuditoria;
    use CreatesContabilidadFixtures;
    use CreatesGastosFixtures;
    use RefreshDatabase;

    private function causarGasto(): int
    {
        Storage::fake('public');
        $this->crearValorUvt();
        $tipoGasto = $this->crearTipoGasto();
        $tercero = $this->crearTerceroFiscal();
        $this->crearParametrizacion($tipoGasto);

        return $this->postJson('/api/v1/gastos', [
            'tipo_gasto_id' => $tipoGasto->id, 'centro_costo_id' => $this->crearCentroCosto()->id, 'tercero_id' => $tercero->id, 'fecha' => '2026-03-01',
            'numero_documento' => 'FAC-109', 'concepto' => 'Servicio', 'valor_bruto' => 1000000,
            'soporte' => UploadedFile::fake()->create('soporte.pdf', 200, 'application/pdf'),
        ])->assertCreated()->json('gasto.id');
    }

    public function test_causar_un_gasto_deja_el_gasto_su_comprobante_y_su_documento_por_pagar(): void
    {
        $admin = $this->actingAsAdmin();

        $gastoId = $this->causarGasto();

        $gasto = $this->assertEventoAuditoria('causar', 'gastos', 'gasto', $gastoId);
        $this->assertSame($admin->empresa_id, $gasto['empresa_id']);
        $this->assertSame('1000000.00', $gasto['valores_nuevos']['valor_bruto']);
        $this->assertSame('FAC-109', $gasto['valores_nuevos']['numero_documento']);

        $comprobante = $this->assertEventoAuditoria('contabilizar', 'contabilidad', 'comprobante');
        $this->assertSame($comprobante['entidad_id'], $gasto['valores_nuevos']['comprobante_id']);
        $this->assertStringContainsString("gastos #{$gastoId}", $comprobante['descripcion']);

        $documento = $this->assertEventoAuditoria('crear', 'contabilidad', 'documento_tercero');
        $this->assertSame($documento['entidad_id'], $gasto['valores_nuevos']['documento_tercero_id']);
    }

    public function test_un_gasto_rechazado_no_deja_ningun_evento(): void
    {
        $this->actingAsAdmin();
        $tipoGasto = $this->crearTipoGasto();

        $this->postJson('/api/v1/gastos', [
            'tipo_gasto_id' => $tipoGasto->id, 'centro_costo_id' => $this->crearCentroCosto()->id, 'fecha' => '2026-03-01', 'numero_documento' => 'FAC-1', 'concepto' => 'Sin parametrizar', 'valor_bruto' => 1000,
        ])->assertStatus(422);

        $this->assertSinEventosDeAuditoria();
    }

    public function test_anular_un_gasto_deja_su_anulacion_el_reverso_y_la_anulacion_del_documento(): void
    {
        $this->actingAsAdmin();
        $gastoId = $this->causarGasto();
        $comprobanteId = $this->assertEventoAuditoria('contabilizar', 'contabilidad', 'comprobante')['entidad_id'];
        $documentoId = $this->assertEventoAuditoria('crear', 'contabilidad', 'documento_tercero')['entidad_id'];

        $this->deleteJson("/api/v1/gastos/{$gastoId}")->assertNoContent();

        $anulacion = $this->assertEventoAuditoria('anular', 'gastos', 'gasto', $gastoId);
        $this->assertSame('causado', $anulacion['valores_anteriores']['estado']);
        $this->assertSame('anulado', $anulacion['valores_nuevos']['estado']);
        $reverso = $this->assertEventoAuditoria('reversar', 'contabilidad', 'comprobante', $comprobanteId);
        $this->assertStringContainsString("gasto #{$gastoId}", $reverso['motivo']);
        $this->assertEventoAuditoria('anular', 'contabilidad', 'documento_tercero', $documentoId);
    }

    public function test_un_gasto_de_otra_empresa_no_se_anula_ni_deja_evento(): void
    {
        $this->actingAsAdmin($this->crearEmpresaDePrueba());
        $ajeno = $this->causarGasto();
        $this->actingAsAdmin();
        $antes = count($this->eventosDeAuditoria());

        $this->deleteJson("/api/v1/gastos/{$ajeno}")->assertNotFound();

        $this->assertCount($antes, $this->eventosDeAuditoria());
        $this->assertSinEventosDeAuditoria('anular');
    }

    public function test_las_tablas_de_parametrizacion_de_gastos_dejan_evento(): void
    {
        $this->actingAsAdmin();

        $tipoId = $this->postJson('/api/v1/gastos/tipos-gasto', ['codigo' => 'ARR', 'nombre' => 'Arriendo'])->assertCreated()->json('tipo_gasto.id');
        $this->patchJson("/api/v1/gastos/tipos-gasto/{$tipoId}", ['activo' => false])->assertOk();
        $actividadId = $this->postJson('/api/v1/gastos/actividades-economicas', ['codigo' => '6492', 'nombre' => 'Otras actividades de crédito'])->assertCreated()->json('actividad_economica.id');

        $this->assertEventoAuditoria('crear', 'gastos', 'tipo_gasto', $tipoId);
        $edicion = $this->assertEventoAuditoria('modificar', 'gastos', 'tipo_gasto', $tipoId);
        $this->assertSame(['activo' => true], $edicion['valores_anteriores']);
        $this->assertSame(['activo' => false], $edicion['valores_nuevos']);
        $this->assertEventoAuditoria('crear', 'gastos', 'actividad_economica', $actividadId);
    }

    public function test_una_nueva_vigencia_de_valor_uvt_deja_su_creacion_y_el_cierre_de_la_anterior(): void
    {
        $this->actingAsAdmin();
        $anterior = $this->crearValorUvt(2025, 47065);

        $nuevaId = $this->postJson('/api/v1/gastos/valores-uvt', ['fecha_desde' => '2026-01-01', 'valor' => 49799])
            ->assertCreated()->json('valor_uvt.id');

        $this->assertEventoAuditoria('crear', 'gastos', 'valor_uvt', $nuevaId);
        $cierre = $this->assertEventoAuditoria('modificar', 'gastos', 'valor_uvt', $anterior->id);
        $this->assertNull($cierre['valores_anteriores']['fecha_hasta'] ?? null);
        $this->assertStringStartsWith('2025-12-31', $cierre['valores_nuevos']['fecha_hasta']);
    }

    public function test_una_nueva_vigencia_de_parametrizacion_deja_su_creacion_y_el_cierre_de_la_anterior(): void
    {
        $this->actingAsAdmin();
        $tipoGasto = $this->crearTipoGasto();
        $anterior = $this->crearParametrizacion($tipoGasto, ['fecha_desde' => '2025-01-01']);

        $nuevaId = $this->postJson('/api/v1/gastos/parametrizaciones', [
            'tipo_gasto_id' => $tipoGasto->id, 'fecha_desde' => '2026-01-01',
            'tipo_comprobante_id' => $this->crearTipoComprobante()->id, 'cuenta_gasto_id' => $this->crearCuenta()->id, 'cuenta_cxp_id' => $this->crearCuenta()->id,
        ])->assertCreated()->json('parametrizacion.id');

        $this->assertEventoAuditoria('crear', 'gastos', 'parametrizacion_tipo_gasto', $nuevaId);
        $cierre = $this->assertEventoAuditoria('modificar', 'gastos', 'parametrizacion_tipo_gasto', $anterior->id);
        $this->assertNull($cierre['valores_anteriores']['fecha_hasta'] ?? null);
        $this->assertStringStartsWith('2025-12-31', $cierre['valores_nuevos']['fecha_hasta']);
    }

    public function test_una_nueva_tarifa_reteica_deja_su_creacion_y_el_cierre_de_la_vigente(): void
    {
        $this->actingAsAdmin();
        $municipio = $this->crearMunicipio();
        $actividad = $this->crearActividadEconomica();
        $anterior = $this->crearTarifaReteIca($municipio, $actividad, 5.5, ['fecha_desde' => '2025-01-01']);

        $nuevaId = $this->postJson('/api/v1/gastos/tarifas-reteica', [
            'municipio_id' => $municipio->id, 'actividad_economica_id' => $actividad->id, 'fecha_desde' => '2026-01-01', 'tarifa' => 6.9,
        ])->assertCreated()->json('tarifa.id');

        $this->assertEventoAuditoria('crear', 'gastos', 'tarifa_rete_ica_municipio', $nuevaId);
        $cierre = $this->assertEventoAuditoria('modificar', 'gastos', 'tarifa_rete_ica_municipio', $anterior->id);
        $this->assertStringStartsWith('2025-12-31', $cierre['valores_nuevos']['fecha_hasta']);
    }
}
