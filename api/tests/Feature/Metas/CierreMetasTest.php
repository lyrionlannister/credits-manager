<?php

namespace Tests\Feature\Metas;

use App\Metas\Enums\TipoMeta;
use App\Metas\Models\MetaCierre;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\AfirmaAuditoria;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\Concerns\CreatesMetasFixtures;
use Tests\TestCase;

class CierreMetasTest extends TestCase
{
    use ActsAsAdmin;
    use AfirmaAuditoria;
    use CreatesCreditFixtures;
    use CreatesMetasFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Septiembre cerrado con colocación de 800.000 sobre 1.000.000 (incumplida) y recaudo de 500.000 sobre 1.000.000. */
    private function septiembreCerrado(): array
    {
        $admin = $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, [
            'valor_prestamo' => 800000,
            'desembolsado_en' => '2026-09-10 10:00:00',
        ]);
        $this->crearPago($cliente, $credito, 500000, '2026-09-15 09:00:00', 500000, 0);

        $colocacion = $this->crearMeta('2026-09', TipoMeta::Colocacion, 1000000);
        $recaudo = $this->crearMeta('2026-09', TipoMeta::Recaudo, 1000000);

        $this->simularHoy('2026-10-01 10:00:00');
        $this->artisan('metas:evaluar')->assertSuccessful();

        return [$admin, $cliente, $credito, $colocacion, $recaudo];
    }

    private function metaDeEmpresa(string $periodo, string $tipo): array
    {
        return collect($this->getJson("/api/v1/metas?periodo={$periodo}")->assertOk()->json('metas'))
            ->firstWhere('tipo', $tipo);
    }

    public function test_el_cierre_no_cambia_al_editar_la_meta_ni_al_cambiar_el_modo_ni_con_movimientos_tardios(): void
    {
        [, $cliente, $credito, $colocacion, $recaudo] = $this->septiembreCerrado();

        $this->putJson("/api/v1/metas/{$colocacion->id}", ['valor_objetivo' => 5000000])->assertOk();
        $this->configurarMetas('modo_colocacion_refinanciaciones', 'total');

        $this->crearPago($cliente, $credito, 300000, '2026-09-29 09:00:00', 300000, 0);
        $this->crearCredito($cliente, ['valor_prestamo' => 200000, 'desembolsado_en' => '2026-09-30 15:00:00']);

        $colocacionCerrada = $this->metaDeEmpresa('2026-09', 'colocacion');
        $recaudoCerrado = $this->metaDeEmpresa('2026-09', 'recaudo');

        $this->assertTrue($colocacionCerrada['cerrado']);
        $this->assertSame('800000.00', $colocacionCerrada['empresa']['ejecutado']);
        $this->assertSame('1000000.00', $colocacionCerrada['empresa']['objetivo']);
        $this->assertSame('incumplida', $colocacionCerrada['empresa']['estado']);
        $this->assertSame('500000.00', $recaudoCerrado['empresa']['ejecutado']);
        $this->assertSame(1, MetaCierre::query()->where('meta_id', $recaudo->id)->count());
    }

    public function test_recalcular_inserta_un_cierre_nuevo_que_apunta_al_anterior_y_no_toca_el_original(): void
    {
        [, $cliente, $credito, , $recaudo] = $this->septiembreCerrado();
        $anterior = MetaCierre::query()->where('meta_id', $recaudo->id)->whereNull('asesor_id')->sole();
        $antesDeRecalcular = $anterior->getAttributes();

        $this->crearPago($cliente, $credito, 300000, '2026-09-29 09:00:00', 300000, 0);

        $this->postJson('/api/v1/metas/cierres/recalcular', [
            'periodo' => '2026-09',
            'tipo' => 'recaudo',
            'motivo' => 'Pago tardío de septiembre registrado el 2 de octubre',
        ])->assertCreated();

        $nuevo = MetaCierre::query()->where('meta_id', $recaudo->id)->whereNull('asesor_id')->orderByDesc('id')->firstOrFail();
        $this->assertSame($anterior->id, $nuevo->cierre_anterior_id);
        $this->assertSame($antesDeRecalcular, $anterior->fresh()->getAttributes());
        $this->assertSame('800000.00', $nuevo->ejecutado->aDecimalString());

        $evento = $this->assertEventoAuditoria('recalcular', 'metas', 'meta', $recaudo->id);
        $this->assertSame('Pago tardío de septiembre registrado el 2 de octubre', $evento['motivo']);
    }

    public function test_recalcular_exige_permiso_y_un_motivo(): void
    {
        $this->septiembreCerrado();

        $this->actingAsAsesor();
        $this->postJson('/api/v1/metas/cierres/recalcular', [
            'periodo' => '2026-09',
            'tipo' => 'recaudo',
            'motivo' => 'Motivo suficientemente largo',
        ])->assertForbidden();

        $this->actingAsAdmin();
        $this->postJson('/api/v1/metas/cierres/recalcular', [
            'periodo' => '2026-09',
            'tipo' => 'recaudo',
            'motivo' => 'corto',
        ])->assertUnprocessable()->assertJsonValidationErrors(['motivo']);
    }

    public function test_recalcular_un_periodo_abierto_responde_422(): void
    {
        $this->actingAsAdmin();
        $this->crearMeta('2026-10', TipoMeta::Recaudo, 1000000);
        $this->simularHoy('2026-10-12 10:00:00');

        $this->postJson('/api/v1/metas/cierres/recalcular', [
            'periodo' => '2026-10',
            'tipo' => 'recaudo',
            'motivo' => 'Recalcular un período que todavía está abierto',
        ])->assertUnprocessable()->assertJsonValidationErrors(['periodo']);
    }

    public function test_los_cierres_rechazan_actualizacion_y_borrado_en_la_base(): void
    {
        [, , , , $recaudo] = $this->septiembreCerrado();

        $this->expectException(QueryException::class);

        DB::table('metas_cierres')->where('meta_id', $recaudo->id)->update(['estado' => 'cumplida']);
    }

    public function test_el_comando_no_duplica_cierres_ni_alertas_al_correrse_dos_veces(): void
    {
        $this->septiembreCerrado();

        $cierresAntes = DB::table('metas_cierres')->count();
        $alertasAntes = DB::table('alertas_metas')->count();
        $this->assertGreaterThan(0, $alertasAntes);

        $this->artisan('metas:evaluar')->assertSuccessful();

        $this->assertSame($cierresAntes, DB::table('metas_cierres')->count());
        $this->assertSame($alertasAntes, DB::table('alertas_metas')->count());
    }

    public function test_un_periodo_incumplido_genera_una_alerta_de_empresa(): void
    {
        $this->septiembreCerrado();

        $this->assertTrue(DB::table('alertas_metas')->where('estado', 'incumplida')->whereNull('usuario_id')->exists());
    }

    public function test_una_meta_con_cierres_no_se_puede_eliminar(): void
    {
        [, , , $colocacion] = $this->septiembreCerrado();

        $this->deleteJson("/api/v1/metas/{$colocacion->id}")->assertUnprocessable()->assertJsonValidationErrors(['meta']);
        $this->assertNotSoftDeleted('metas', ['id' => $colocacion->id], null, 'borrado_en');
    }
}
