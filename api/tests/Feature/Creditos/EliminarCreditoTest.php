<?php

namespace Tests\Feature\Creditos;

use App\Contabilidad\Enums\EstadoComprobante;
use App\Contabilidad\Enums\EventoContable;
use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Enums\TipoReprogramacion;
use App\Creditos\Repositories\ReprogramacionCreditoRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

class EliminarCreditoTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesContabilidadFixtures;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    public function test_elimina_un_credito_sin_pagos_y_borra_sus_cuotas(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente);
        $cuota = $this->crearCuota($credito);

        $respuesta = $this->deleteJson("/api/v1/creditos/{$credito->id}");

        $respuesta->assertStatus(204);
        $this->assertSoftDeleted('creditos', ['id' => $credito->id]);
        $this->assertSoftDeleted('cuotas', ['id' => $cuota->id]);
    }

    /**
     * Un crédito con pagos ya no es intocable: eliminar los reversa todos (movimiento compensatorio, nunca se
     * editan ni se borran) y después el comprobante de desembolso, antes de borrar el crédito lógicamente.
     */
    public function test_elimina_un_credito_desembolsado_con_pagos_reversandolos_todos(): void
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, ['estado' => EstadoCredito::Aprobado]);
        $this->postJson("/api/v1/creditos/{$credito->id}/desembolsar")->assertOk();
        $cuota = $this->crearCuota($credito, ['valor' => 300, 'valor_capital' => 300, 'fecha_vencimiento' => now()->addMonth()->toDateString()]);

        $pagoId = $this->postJson("/api/v1/creditos/{$credito->id}/pagos", ['valor_pago' => 100, 'forma_pago' => 'efectivo'])
            ->assertCreated()->json('pago.id');

        $respuesta = $this->deleteJson("/api/v1/creditos/{$credito->id}");

        $respuesta->assertStatus(204);
        $this->assertSoftDeleted('creditos', ['id' => $credito->id]);
        $this->assertSoftDeleted('cuotas', ['id' => $cuota->id]);
        $this->assertDatabaseHas('reversas_pago', ['pago_id' => $pagoId]);
        $this->assertDatabaseHas('pagos', ['id' => $pagoId]); // el pago nunca se edita ni se borra, solo se reversa.
        $this->assertDatabaseHas('comprobantes', [
            'modulo_origen' => 'creditos', 'origen_id' => $credito->id, 'estado' => EstadoComprobante::Anulado->value,
        ]);
    }

    public function test_rechaza_eliminar_credito_con_pago_no_reversable(): void
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, ['estado' => EstadoCredito::Aprobado]);
        $this->postJson("/api/v1/creditos/{$credito->id}/desembolsar")->assertOk();
        $this->crearCuota($credito, ['valor' => 300, 'valor_capital' => 300, 'fecha_vencimiento' => now()->addMonth()->toDateString()]);
        $this->postJson("/api/v1/creditos/{$credito->id}/pagos", ['valor_pago' => 100, 'forma_pago' => 'efectivo'])->assertCreated();

        app(ReprogramacionCreditoRepository::class)->crear([
            'credito_id' => $credito->id,
            'tipo' => TipoReprogramacion::Reprogramacion,
            'motivo' => 'Reprogramación posterior al pago',
            'plan_anterior' => [],
            'creado_por' => null,
        ]);

        $respuesta = $this->deleteJson("/api/v1/creditos/{$credito->id}");

        $respuesta->assertStatus(422)->assertJsonValidationErrors('pago');
        $this->assertDatabaseHas('creditos', ['id' => $credito->id, 'borrado_en' => null]);
    }

    public function test_elimina_credito_desembolsado_reversa_su_comprobante(): void
    {
        $this->actingAsAdmin();
        $this->crearRegla(EventoContable::DesembolsoCredito);
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, ['estado' => EstadoCredito::Aprobado]);
        $this->postJson("/api/v1/creditos/{$credito->id}/desembolsar")->assertStatus(200);

        $respuesta = $this->deleteJson("/api/v1/creditos/{$credito->id}");

        $respuesta->assertStatus(204);
        $this->assertSoftDeleted('creditos', ['id' => $credito->id]);
        $this->assertDatabaseHas('comprobantes', [
            'modulo_origen' => 'creditos',
            'origen_id' => $credito->id,
            'estado' => EstadoComprobante::Anulado->value,
        ]);
    }

    public function test_credito_inexistente_devuelve_404(): void
    {
        $this->actingAsAdmin();

        $respuesta = $this->deleteJson('/api/v1/creditos/999999');

        $respuesta->assertStatus(404);
    }

    public function test_sin_autenticar_no_puede_eliminar(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente);
        $this->salirDeSesion();

        $respuesta = $this->deleteJson("/api/v1/creditos/{$credito->id}");

        $respuesta->assertStatus(401);
    }

    public function test_asesor_sin_permiso_no_puede_eliminar(): void
    {
        $usuario = $this->actingAsAsesor();
        $cliente = $this->crearCliente(['empresa_id' => $usuario->empresa_id]);
        $credito = $this->crearCredito($cliente, ['empresa_id' => $usuario->empresa_id]);

        $respuesta = $this->deleteJson("/api/v1/creditos/{$credito->id}");

        $respuesta->assertStatus(403);
    }
}
