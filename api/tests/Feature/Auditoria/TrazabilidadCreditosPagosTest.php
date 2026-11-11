<?php

namespace Tests\Feature\Auditoria;

use App\Contabilidad\Enums\EventoContable;
use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Enums\TipoCredito;
use App\Creditos\Enums\TipoPago;
use App\Creditos\Models\Credito;
use App\Creditos\Services\CuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\AfirmaAuditoria;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

class TrazabilidadCreditosPagosTest extends TestCase
{
    use ActsAsAdmin;
    use AfirmaAuditoria;
    use CreatesContabilidadFixtures;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    private function payloadCredito(int $clienteId, array $cambios = []): array
    {
        return array_merge([
            'cliente_id' => $clienteId,
            'tipo_credito' => TipoCredito::Nuevo->value,
            'valor_prestamo' => 1200000,
            'tasa_interes' => 2,
            'plazo' => 12,
            'tipo_pago' => TipoPago::Mensual->value,
            'fecha_desembolso' => '2026-01-01',
        ], $cambios);
    }

    public function test_crear_un_credito_emite_un_evento_con_sus_cuotas_y_la_empresa_del_autor(): void
    {
        $admin = $this->actingAsAdmin();
        $cliente = $this->crearCliente();

        $creditoId = $this->postJson('/api/v1/creditos', $this->payloadCredito($cliente->id))->assertCreated()->json('credito.id');

        $evento = $this->assertEventoAuditoria('crear', 'creditos', 'credito', $creditoId);
        $this->assertSame($admin->empresa_id, $evento['empresa_id']);
        $this->assertSame($admin->id, $evento['autor']['usuario_id']);
        $this->assertSame(12, $evento['valores_nuevos']['cuotas']);
        $this->assertSame('solicitado', $evento['valores_nuevos']['estado']);
        $this->assertSame('1200000.00', $evento['valores_nuevos']['valor_prestamo']);
    }

    public function test_una_validacion_fallida_al_crear_un_credito_no_deja_evento(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/creditos', [])->assertStatus(422);

        $this->assertSinEventosDeAuditoria();
    }

    public function test_una_refinanciacion_deja_el_evento_del_origen_solo_al_desembolsarla(): void
    {
        $this->actingAsAdmin();
        $this->crearRegla(EventoContable::DesembolsoCredito);
        $cliente = $this->crearCliente();
        $origen = $this->crearCredito($cliente, ['estado' => EstadoCredito::Desembolsado]);
        $this->crearCuota($origen);

        $payload = $this->payloadCredito($cliente->id, [
            'tipo_credito' => TipoCredito::Refinanciacion->value,
            'credito_origen_id' => $origen->id,
            'valor_adicional' => 500000,
        ]);
        unset($payload['valor_prestamo']);

        $nuevoId = $this->postJson('/api/v1/creditos', $payload)->assertCreated()->json('credito.id');

        $this->assertEventoAuditoria('crear', 'creditos', 'credito', $nuevoId);
        $this->assertCount(0, $this->eventosDeAuditoria('modificar', 'creditos'), 'Al solicitar, el original no cambia.');

        $this->postJson("/api/v1/creditos/{$nuevoId}/aprobar")->assertOk();
        $this->postJson("/api/v1/creditos/{$nuevoId}/desembolsar")->assertOk();

        $cierre = $this->assertEventoAuditoria('modificar', 'creditos', 'credito', $origen->id);
        $this->assertSame('desembolsado', $cierre['valores_anteriores']['estado']);
        $this->assertSame('refinanciado', $cierre['valores_nuevos']['estado']);
        $this->assertStringContainsString("#{$nuevoId}", $cierre['descripcion']);
    }

    public function test_aprobar_deja_un_evento_con_el_estado_anterior_y_el_nuevo(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCredito($this->crearCliente(), ['estado' => EstadoCredito::Solicitado]);

        $this->postJson("/api/v1/creditos/{$credito->id}/aprobar")->assertOk();

        $evento = $this->assertEventoAuditoria('aprobar', 'creditos', 'credito', $credito->id);
        $this->assertSame(['estado' => 'solicitado'], $evento['valores_anteriores']);
        $this->assertSame(['estado' => 'aprobado'], $evento['valores_nuevos']);
    }

    public function test_una_transicion_invalida_no_deja_evento(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCredito($this->crearCliente(), ['estado' => EstadoCredito::Desembolsado]);

        $this->postJson("/api/v1/creditos/{$credito->id}/aprobar")->assertStatus(422);

        $this->assertSinEventosDeAuditoria();
    }

    public function test_desembolsar_sin_regla_contable_se_rechaza_y_no_deja_evento(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCredito($this->crearCliente(), ['estado' => EstadoCredito::Aprobado]);

        $this->postJson("/api/v1/creditos/{$credito->id}/desembolsar")
            ->assertStatus(422)
            ->assertJsonValidationErrors('contabilidad');

        $this->assertSinEventosDeAuditoria();
        $this->assertSame(EstadoCredito::Aprobado, $credito->fresh()->estado);
    }

    public function test_desembolsar_con_regla_deja_el_evento_del_credito_y_el_del_comprobante_enlazados(): void
    {
        $this->actingAsAdmin();
        $this->crearRegla(EventoContable::DesembolsoCredito);
        $credito = $this->crearCredito($this->crearCliente(), ['estado' => EstadoCredito::Aprobado]);

        $this->postJson("/api/v1/creditos/{$credito->id}/desembolsar")->assertOk();

        $desembolso = $this->assertEventoAuditoria('desembolsar', 'creditos', 'credito', $credito->id);
        $comprobante = $this->assertEventoAuditoria('contabilizar', 'contabilidad', 'comprobante');
        $this->assertTrue($desembolso['valores_nuevos']['contabilizado']);
        $this->assertSame($comprobante['entidad_id'], $desembolso['valores_nuevos']['comprobante_id']);
        $this->assertStringContainsString("creditos #{$credito->id}", $comprobante['descripcion']);
        $this->assertSame(2, $comprobante['valores_nuevos']['lineas']);
    }

    public function test_cancelar_un_credito_desembolsado_deja_el_reverso_del_comprobante_y_la_cancelacion(): void
    {
        $this->actingAsAdmin();
        $this->crearRegla(EventoContable::DesembolsoCredito);
        $credito = $this->crearCredito($this->crearCliente(), ['estado' => EstadoCredito::Aprobado]);
        $this->postJson("/api/v1/creditos/{$credito->id}/desembolsar")->assertOk();
        $comprobanteId = $this->assertEventoAuditoria('contabilizar', 'contabilidad', 'comprobante')['entidad_id'];

        $this->postJson("/api/v1/creditos/{$credito->id}/cancelar")->assertOk();

        $reverso = $this->assertEventoAuditoria('reversar', 'contabilidad', 'comprobante', $comprobanteId);
        $this->assertStringContainsString('Cancelación del crédito', $reverso['motivo']);
        $this->assertSame('contabilizado', $reverso['valores_anteriores']['estado']);
        $this->assertSame('anulado', $reverso['valores_nuevos']['estado']);
        $this->assertEventoAuditoria('cancelar', 'creditos', 'credito', $credito->id);
        $this->assertCount(2, $this->eventosDeAuditoria('contabilizar', 'contabilidad'), 'el reverso también es un comprobante contabilizado');
    }

    public function test_eliminar_un_credito_deja_un_evento_con_su_ultimo_estado(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCredito($this->crearCliente(), ['estado' => EstadoCredito::Solicitado]);

        $this->deleteJson("/api/v1/creditos/{$credito->id}")->assertNoContent();

        $evento = $this->assertEventoAuditoria('eliminar', 'creditos', 'credito', $credito->id);
        $this->assertSame('solicitado', $evento['valores_anteriores']['estado']);
        $this->assertNull($evento['valores_nuevos']);
    }

    public function test_un_pago_deja_su_desglose_y_su_abono_a_capital_y_marca_el_credito_saldado(): void
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $credito = $this->crearCredito($this->crearCliente(), ['valor_prestamo' => 300]);
        $cuota = $this->crearCuota($credito, ['valor' => 300, 'valor_interes' => 50, 'valor_capital' => 250]);

        $pagoId = $this->postJson("/api/v1/creditos/{$credito->id}/pagos", ['valor_pago' => 300, 'forma_pago' => 'efectivo'])
            ->assertCreated()->json('pago.id');

        $pago = $this->assertEventoAuditoria('crear', 'pagos', 'pago', $pagoId);
        $this->assertSame('250.00', $pago['valores_nuevos']['capital']);
        $this->assertSame('50.00', $pago['valores_nuevos']['interes']);
        $this->assertSame('0.00', $pago['valores_nuevos']['abono_capital']);
        $this->assertSame([$cuota->id], $pago['valores_nuevos']['cuotas_afectadas']);
        $this->assertSame('300.00', $pago['valores_nuevos']['valor_pago']);

        $saldado = $this->assertEventoAuditoria('modificar', 'creditos', 'credito', $credito->id);
        $this->assertSame('pagado', $saldado['valores_nuevos']['estado']);
    }

    public function test_un_pago_parcial_no_marca_el_credito_como_saldado(): void
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $credito = $this->crearCredito($this->crearCliente());
        $this->crearCuota($credito, ['numero_cuota' => 1]);
        $this->crearCuota($credito, ['numero_cuota' => 2]);

        $this->postJson("/api/v1/creditos/{$credito->id}/pagos", ['valor_pago' => 100, 'forma_pago' => 'efectivo'])->assertCreated();

        $eventos = $this->eventosDeAuditoria('crear', 'pagos');

        $this->assertSame(['pago', 'recibo_caja'], collect($eventos)->pluck('entidad')->sort()->values()->all());
        $this->assertSinEventosDeAuditoria('modificar', 'creditos');
    }

    public function test_el_pago_con_regla_contable_enlaza_su_comprobante(): void
    {
        $this->actingAsAdmin();
        $this->crearRegla(EventoContable::RecaudoCapital);
        $this->crearRegla(EventoContable::RecaudoInteres);
        $credito = $this->crearCredito($this->crearCliente());
        $this->crearCuota($credito);

        $this->postJson("/api/v1/creditos/{$credito->id}/pagos", ['valor_pago' => 100, 'forma_pago' => 'efectivo'])->assertCreated();

        $pago = $this->eventosDeAuditoria('crear', 'pagos')[0];
        $comprobante = $this->assertEventoAuditoria('contabilizar', 'contabilidad', 'comprobante');
        $this->assertSame($comprobante['entidad_id'], $pago['valores_nuevos']['comprobante_id']);
    }

    public function test_un_pago_rechazado_no_deja_evento(): void
    {
        $this->actingAsAdmin();
        $credito = $this->crearCredito($this->crearCliente(), ['estado' => EstadoCredito::Aprobado]);

        $this->postJson("/api/v1/creditos/{$credito->id}/pagos", ['valor_pago' => 100, 'forma_pago' => 'efectivo'])->assertStatus(422);

        $this->assertSinEventosDeAuditoria();
    }

    public function test_la_liquidacion_anticipada_deja_el_pago_y_el_credito_saldado(): void
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $credito = $this->crearCredito($this->crearCliente());
        $this->crearCuota($credito);

        $respuesta = $this->postJson("/api/v1/creditos/{$credito->id}/liquidacion", ['forma_pago' => 'efectivo'])->assertCreated();

        $pago = $this->assertEventoAuditoria('crear', 'pagos', 'pago', $respuesta->json('pago.id'));
        $this->assertStringContainsString('Liquidación anticipada', $pago['descripcion']);
        $this->assertSame('pagado', $this->assertEventoAuditoria('modificar', 'creditos', 'credito', $credito->id)['valores_nuevos']['estado']);
    }

    public function test_leer_creditos_con_cuotas_vencidas_deja_un_evento_de_mora_y_no_se_repite(): void
    {
        $admin = $this->actingAsAdmin();
        $credito = $this->crearCredito($this->crearCliente());
        $this->crearCuota($credito, ['numero_cuota' => 1, 'fecha_vencimiento' => now()->subDays(10)->toDateString()]);
        $this->crearCuota($credito, ['numero_cuota' => 2, 'fecha_vencimiento' => now()->subDays(5)->toDateString()]);
        $this->crearCuota($credito, ['numero_cuota' => 3, 'fecha_vencimiento' => now()->addDays(20)->toDateString()]);

        $this->getJson('/api/v1/creditos')->assertOk();
        $this->getJson('/api/v1/creditos')->assertOk();

        $eventos = $this->eventosDeAuditoria('modificar', 'cartera');
        $this->assertCount(1, $eventos);
        $this->assertSame($admin->empresa_id, $eventos[0]['empresa_id']);
        $this->assertSame(2, $eventos[0]['valores_nuevos']['cuotas']);
        $this->assertSame([$credito->id], $eventos[0]['valores_nuevos']['creditos']);
        $this->assertSame('pendiente', $eventos[0]['valores_anteriores']['estado']);
        $this->assertSame('vencida', $eventos[0]['valores_nuevos']['estado']);
        $this->assertDatabaseCount('cuotas', 3);
    }

    public function test_la_mora_sin_sesion_emite_un_evento_por_empresa_con_su_propio_empresa_id(): void
    {
        $empresaA = $this->crearEmpresaDePrueba();
        $empresaB = $this->crearEmpresaDePrueba();
        $creditosPorEmpresa = [];

        foreach ([$empresaA, $empresaB] as $empresa) {
            $this->actingAsAdmin($empresa);
            $credito = $this->crearCredito($this->crearCliente());
            $this->crearCuota($credito, ['fecha_vencimiento' => now()->subDays(3)->toDateString()]);
            $creditosPorEmpresa[$empresa->id] = $credito->id;
        }

        $this->salirDeSesion();
        app(CuotaService::class)->actualizarVencidas();

        $eventos = collect($this->eventosDeAuditoria('modificar', 'cartera'))->keyBy('empresa_id');
        $this->assertCount(2, $eventos);

        foreach ($creditosPorEmpresa as $empresaId => $creditoId) {
            $this->assertSame([$creditoId], $eventos[$empresaId]['valores_nuevos']['creditos'], 'cada empresa solo ve sus propios créditos');
            $this->assertNull($eventos[$empresaId]['autor']['usuario_id']);
        }
    }

    public function test_los_eventos_del_credito_de_una_empresa_no_se_atribuyen_a_otra(): void
    {
        $otraEmpresa = $this->crearEmpresaDePrueba();
        $this->actingAsAdmin($otraEmpresa);
        $ajeno = $this->crearCredito($this->crearCliente(), ['estado' => EstadoCredito::Solicitado]);

        $admin = $this->actingAsAdmin();
        $this->postJson("/api/v1/creditos/{$ajeno->id}/aprobar")->assertNotFound();

        $this->assertSinEventosDeAuditoria();
        $this->assertSame(EstadoCredito::Solicitado, Credito::query()->withoutGlobalScopes()->find($ajeno->id)->estado);
        $this->assertNotSame($admin->empresa_id, $otraEmpresa->id);
    }
}
