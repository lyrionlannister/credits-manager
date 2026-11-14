<?php

namespace Tests\Feature\Creditos;

use App\Clientes\Models\Cliente;
use App\Contabilidad\Enums\EventoContable;
use App\Contabilidad\Models\Comprobante;
use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Enums\TipoCredito;
use App\Creditos\Models\Credito;
use App\Creditos\Services\CuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

class RefinanciacionTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesContabilidadFixtures;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    private Cliente $cliente;

    /** Origen desembolsado con una cuota pagada y otra pendiente. */
    private function crearOrigen(): Credito
    {
        $this->actingAsAdmin();
        $this->cliente = $this->crearCliente();
        $origen = $this->crearCredito($this->cliente);
        $this->crearCuota($origen, ['numero_cuota' => 1, 'estado' => EstadoCuota::Pagada, 'valor' => 300, 'valor_pagado' => 300]);
        $this->crearCuota($origen, ['numero_cuota' => 2, 'estado' => EstadoCuota::Pendiente, 'valor' => 300, 'valor_pagado' => 0, 'fecha_vencimiento' => '2026-03-01']);

        return $origen;
    }

    /**
     * Sin `valor_prestamo`: lo calcula el servidor (capital pendiente del original + `valor_adicional`). Previsualiza
     * primero, como hace el formulario real, y manda ese saldo con la creación — el servidor lo exige para
     * detectar si el saldo del original cambió entre la previsualización y este envío. Si el caso ya sabe qué
     * saldo espera (p. ej. un original sin saldo), pásalo en `$cambios` y se usa tal cual, sin previsualizar.
     */
    private function solicitarRefinanciacion(Credito $origen, ?Cliente $cliente = null, array $cambios = []): TestResponse
    {
        $datos = array_merge([
            'cliente_id' => ($cliente ?? $this->cliente)->id,
            'tipo_credito' => TipoCredito::Refinanciacion->value,
            'credito_origen_id' => $origen->id,
            'valor_adicional' => 500,
            'tasa_interes' => 5,
            'plazo' => 3,
            'tipo_pago' => 'mensual',
            'fecha_desembolso' => '2026-04-01',
        ], $cambios);

        if (! array_key_exists('saldo_refinanciado_previsualizado', $cambios)) {
            $preview = $this->postJson('/api/v1/creditos/previsualizar', $datos);
            $datos['saldo_refinanciado_previsualizado'] = $preview->status() < 300 ? $preview->json('saldo_refinanciado') : null;
        }

        return $this->postJson('/api/v1/creditos', $datos);
    }

    /** Solicita y aprueba la refinanciación, sin desembolsarla todavía; devuelve su id. */
    private function aprobarRefinanciacion(Credito $origen): int
    {
        $id = $this->solicitarRefinanciacion($origen)->assertCreated()->json('credito.id');
        $this->postJson("/api/v1/creditos/{$id}/aprobar")->assertOk();

        return $id;
    }

    /** Origen ya refinanciado: solicitud, aprobación y desembolso de la refinanciación. */
    private function refinanciar(): Credito
    {
        $origen = $this->crearOrigen();
        $this->crearRegla(EventoContable::DesembolsoCredito);
        $id = $this->aprobarRefinanciacion($origen);
        $this->postJson("/api/v1/creditos/{$id}/desembolsar")->assertOk();

        return $origen->fresh();
    }

    private function refinanciacionDe(Credito $origen): Credito
    {
        return Credito::where('credito_origen_id', $origen->id)->firstOrFail();
    }

    public function test_el_valor_del_prestamo_es_el_capital_pendiente_del_original_mas_el_valor_adicional(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $origen = $this->crearCredito($cliente, ['valor_prestamo' => 900]);
        // Interés primero: la cuota 1 queda pagada (50 de interés y 250 de capital), la 2 tiene un abono de 100 (50 y 50) y la 3 nada.
        $this->crearCuota($origen, ['numero_cuota' => 1, 'valor' => 300, 'valor_interes' => 50, 'valor_capital' => 250, 'valor_pagado' => 300, 'estado' => EstadoCuota::Pagada]);
        $this->crearCuota($origen, ['numero_cuota' => 2, 'valor' => 300, 'valor_interes' => 50, 'valor_capital' => 250, 'valor_pagado' => 100]);
        $this->crearCuota($origen, ['numero_cuota' => 3, 'valor' => 300, 'valor_interes' => 50, 'valor_capital' => 250]);

        $respuesta = $this->solicitarRefinanciacion($origen, $cliente, ['valor_adicional' => '400.50'])->assertCreated();

        $respuesta->assertJsonPath('credito.saldo_refinanciado', '600.00');
        $respuesta->assertJsonPath('credito.valor_prestamo', '1000.50');
        $this->assertCount(3, $respuesta->json('credito.cuotas'));
        $this->assertDatabaseHas('creditos', ['id' => $respuesta->json('credito.id'), 'saldo_refinanciado' => 60000, 'valor_prestamo' => 100050]);
    }

    public function test_una_refinanciacion_sin_dinero_nuevo_toma_solo_el_saldo_del_original(): void
    {
        $origen = $this->crearOrigen();

        $respuesta = $this->solicitarRefinanciacion($origen, null, ['valor_adicional' => 0])->assertCreated();

        $respuesta->assertJsonPath('credito.saldo_refinanciado', '600.00');
        $respuesta->assertJsonPath('credito.valor_prestamo', '600.00');
    }

    public function test_una_refinanciacion_sin_saldo_ni_dinero_nuevo_se_rechaza(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $origen = $this->crearCredito($cliente, ['valor_prestamo' => 300]);
        $this->crearCuota($origen, ['numero_cuota' => 1, 'valor' => 300, 'valor_capital' => 300, 'valor_pagado' => 300, 'estado' => EstadoCuota::Pagada]);

        $this->solicitarRefinanciacion($origen, $cliente, ['valor_adicional' => 0, 'saldo_refinanciado_previsualizado' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('valor_adicional');

        $this->assertDatabaseCount('creditos', 1);
    }

    public function test_en_una_refinanciacion_el_valor_del_prestamo_no_se_envia_y_el_adicional_es_obligatorio(): void
    {
        $origen = $this->crearOrigen();

        $this->solicitarRefinanciacion($origen, null, ['valor_prestamo' => 500])->assertStatus(422)->assertJsonValidationErrors('valor_prestamo');
        $this->solicitarRefinanciacion($origen, null, ['valor_adicional' => null])->assertStatus(422)->assertJsonValidationErrors('valor_adicional');
        $this->assertDatabaseCount('creditos', 1);
    }

    public function test_un_credito_nuevo_no_acepta_valor_adicional(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();

        $this->postJson('/api/v1/creditos', [
            'cliente_id' => $cliente->id,
            'tipo_credito' => TipoCredito::Nuevo->value,
            'valor_prestamo' => 1000,
            'valor_adicional' => 500,
            'tasa_interes' => 5,
            'plazo' => 3,
            'tipo_pago' => 'mensual',
            'fecha_desembolso' => '2026-04-01',
        ])->assertStatus(422)->assertJsonValidationErrors('valor_adicional');
    }

    public function test_si_el_saldo_del_original_cambia_antes_del_desembolso_no_se_puede_desembolsar(): void
    {
        $origen = $this->crearOrigen();
        $id = $this->aprobarRefinanciacion($origen);
        $this->crearReglasDeCreditoYPago();

        $this->postJson("/api/v1/creditos/{$origen->id}/pagos", ['valor_pago' => 100, 'forma_pago' => 'efectivo'])->assertCreated();

        $this->postJson("/api/v1/creditos/{$id}/desembolsar")
            ->assertStatus(422)
            ->assertJsonPath('errors.credito_origen_id.0', 'El saldo de capital del crédito original cambió desde la solicitud; cancela esta refinanciación y solicítala de nuevo.');

        $this->assertSame(EstadoCredito::Aprobado, Credito::findOrFail($id)->estado);
        $this->assertSame(EstadoCredito::Desembolsado, $origen->fresh()->estado);
    }

    /**
     * Entre previsualizar y crear, el original recibió un pago: el saldo que el usuario vio ya no es el actual.
     * El servidor rechaza en vez de crear la refinanciación con un saldo distinto del aprobado.
     */
    public function test_si_el_saldo_del_original_cambia_entre_previsualizar_y_crear_se_rechaza(): void
    {
        $origen = $this->crearOrigen();
        $this->crearReglasDeCreditoYPago();

        $preview = $this->postJson('/api/v1/creditos/previsualizar', [
            'cliente_id' => $this->cliente->id,
            'tipo_credito' => TipoCredito::Refinanciacion->value,
            'credito_origen_id' => $origen->id,
            'valor_adicional' => 500,
            'tasa_interes' => 5,
            'plazo' => 3,
            'tipo_pago' => 'mensual',
            'fecha_desembolso' => '2026-04-01',
        ])->assertOk();
        $saldoPrevisualizado = $preview->json('saldo_refinanciado');
        $this->assertSame('600.00', $saldoPrevisualizado);

        $this->postJson("/api/v1/creditos/{$origen->id}/pagos", ['valor_pago' => 100, 'forma_pago' => 'efectivo'])->assertCreated();

        $this->solicitarRefinanciacion($origen, null, ['saldo_refinanciado_previsualizado' => $saldoPrevisualizado])
            ->assertStatus(422)
            ->assertJsonPath('errors.credito_origen_id.0', 'El saldo de capital del crédito original cambió desde que se calculó la previsualización; vuelve a previsualizar antes de crear la refinanciación.');

        $this->assertDatabaseCount('creditos', 1);
    }

    public function test_el_asiento_del_desembolso_registra_solo_el_dinero_nuevo(): void
    {
        $origen = $this->crearOrigen();
        $this->crearRegla(EventoContable::DesembolsoCredito);
        $id = $this->solicitarRefinanciacion($origen, null, ['valor_adicional' => 500])->assertCreated()->json('credito.id');
        $this->postJson("/api/v1/creditos/{$id}/aprobar")->assertOk();

        $this->postJson("/api/v1/creditos/{$id}/desembolsar")->assertOk();

        $this->assertSame('1100.00', Credito::findOrFail($id)->valor_prestamo->aDecimalString());
        $comprobante = Comprobante::where('modulo_origen', 'creditos')->where('origen_id', $id)->firstOrFail();
        $this->assertSame('500.00', $comprobante->total_debito->aDecimalString(), 'El saldo tomado ya estaba en cartera: no mueve caja otra vez.');
    }

    public function test_una_refinanciacion_sin_dinero_nuevo_no_genera_asiento_de_desembolso(): void
    {
        $origen = $this->crearOrigen();
        $this->crearRegla(EventoContable::DesembolsoCredito);
        $id = $this->solicitarRefinanciacion($origen, null, ['valor_adicional' => 0])->assertCreated()->json('credito.id');
        $this->postJson("/api/v1/creditos/{$id}/aprobar")->assertOk();

        $this->postJson("/api/v1/creditos/{$id}/desembolsar")->assertOk();

        $this->assertSame(EstadoCredito::Refinanciado, $origen->fresh()->estado);
        $this->assertDatabaseCount('comprobantes', 0);
    }

    public function test_el_detalle_y_el_estado_de_cuenta_del_original_apuntan_a_la_refinanciacion(): void
    {
        $origen = $this->refinanciar();
        $refinanciacion = $this->refinanciacionDe($origen);

        foreach (["/api/v1/creditos/{$origen->id}", "/api/v1/creditos/{$origen->id}/estado-cuenta"] as $ruta) {
            $this->getJson($ruta)
                ->assertOk()
                ->assertJsonPath('refinanciacion.tipo', 'refinanciado')
                ->assertJsonPath('refinanciacion.credito_relacionado.id', $refinanciacion->id)
                ->assertJsonPath('refinanciacion.credito_relacionado.estado', 'desembolsado')
                ->assertJsonPath('refinanciacion.credito_relacionado.valor_prestamo', '1100.00');
        }
    }

    public function test_el_detalle_y_el_estado_de_cuenta_de_la_refinanciacion_apuntan_al_original_y_desglosan_el_valor(): void
    {
        $origen = $this->refinanciar();
        $refinanciacion = $this->refinanciacionDe($origen);

        foreach (["/api/v1/creditos/{$refinanciacion->id}", "/api/v1/creditos/{$refinanciacion->id}/estado-cuenta"] as $ruta) {
            $this->getJson($ruta)
                ->assertOk()
                ->assertJsonPath('refinanciacion.tipo', 'refinancia')
                ->assertJsonPath('refinanciacion.credito_relacionado.id', $origen->id)
                ->assertJsonPath('refinanciacion.credito_relacionado.estado', 'refinanciado')
                ->assertJsonPath('refinanciacion.saldo_tomado', '600.00')
                ->assertJsonPath('refinanciacion.valor_adicional', '500.00');
        }
    }

    public function test_una_refinanciacion_solicitada_ya_apunta_al_original_pero_el_original_aun_no(): void
    {
        $origen = $this->crearOrigen();
        $id = $this->solicitarRefinanciacion($origen)->assertCreated()->json('credito.id');

        $this->getJson("/api/v1/creditos/{$id}")->assertOk()->assertJsonPath('refinanciacion.credito_relacionado.id', $origen->id);
        $this->getJson("/api/v1/creditos/{$origen->id}")->assertOk()->assertJsonPath('refinanciacion', null);
    }

    public function test_un_credito_sin_refinanciacion_no_lleva_vinculo(): void
    {
        $origen = $this->crearOrigen();

        $this->getJson("/api/v1/creditos/{$origen->id}")->assertOk()->assertJsonPath('refinanciacion', null);
        $this->getJson("/api/v1/creditos/{$origen->id}/estado-cuenta")->assertOk()->assertJsonPath('refinanciacion', null);
    }

    public function test_una_refinanciacion_anterior_al_saldo_guardado_no_desglosa_el_valor(): void
    {
        $origen = $this->crearOrigen();
        $antigua = $this->crearCredito($this->cliente, [
            'tipo_credito' => TipoCredito::Refinanciacion,
            'credito_origen_id' => $origen->id,
            'saldo_refinanciado' => null,
        ]);

        $this->getJson("/api/v1/creditos/{$antigua->id}")
            ->assertOk()
            ->assertJsonPath('refinanciacion.tipo', 'refinancia')
            ->assertJsonPath('refinanciacion.saldo_tomado', null)
            ->assertJsonPath('refinanciacion.valor_adicional', null);
    }

    public function test_al_solicitar_la_refinanciacion_el_original_sigue_desembolsado_y_recibe_pagos(): void
    {
        $origen = $this->crearOrigen();
        $this->crearReglasDeCreditoYPago();

        $this->solicitarRefinanciacion($origen)->assertCreated();

        $this->assertSame(EstadoCredito::Desembolsado, $origen->fresh()->estado);
        $this->postJson("/api/v1/creditos/{$origen->id}/pagos", ['valor_pago' => 100, 'forma_pago' => 'efectivo'])->assertCreated();
    }

    public function test_al_desembolsar_la_refinanciacion_el_original_queda_refinanciado_y_su_historial_intacto(): void
    {
        $origen = $this->refinanciar();

        $this->assertSame(EstadoCredito::Refinanciado, $origen->estado);
        $this->assertSame(EstadoCredito::Desembolsado, $this->refinanciacionDe($origen)->estado);
        $this->assertSame(['pagada', 'pendiente'], $origen->cuotas()->orderBy('numero_cuota')->get()->map(fn ($cuota) => $cuota->estado->value)->all());
        $this->assertSame('0.00', $origen->cuotas()->where('numero_cuota', 2)->first()->valor_pagado->aDecimalString());
    }

    public function test_si_el_original_deja_de_estar_desembolsado_no_se_puede_desembolsar_la_refinanciacion(): void
    {
        $origen = $this->crearOrigen();
        $id = $this->aprobarRefinanciacion($origen);
        $origen->update(['estado' => EstadoCredito::Pagado]);

        $this->postJson("/api/v1/creditos/{$id}/desembolsar")
            ->assertStatus(422)
            ->assertJsonPath('errors.credito_origen_id.0', 'El crédito original ya no está desembolsado; no se puede desembolsar esta refinanciación.');

        $refinanciacion = Credito::findOrFail($id);
        $this->assertSame(EstadoCredito::Aprobado, $refinanciacion->estado);
        $this->assertNull($refinanciacion->desembolsado_en);
        $this->assertSame(EstadoCredito::Pagado, $origen->fresh()->estado);
    }

    public function test_una_refinanciacion_solicitada_o_aprobada_se_cancela_sin_tocar_al_original(): void
    {
        $origen = $this->crearOrigen();

        $solicitada = $this->solicitarRefinanciacion($origen)->assertCreated()->json('credito.id');
        $this->postJson("/api/v1/creditos/{$solicitada}/cancelar")->assertOk();

        $aprobada = $this->aprobarRefinanciacion($origen);
        $this->postJson("/api/v1/creditos/{$aprobada}/cancelar")->assertOk();

        $this->assertSame(EstadoCredito::Desembolsado, $origen->fresh()->estado);
    }

    public function test_no_se_puede_cancelar_ni_eliminar_una_refinanciacion_desembolsada(): void
    {
        $origen = $this->refinanciar();
        $refinanciacion = $this->refinanciacionDe($origen);

        $this->postJson("/api/v1/creditos/{$refinanciacion->id}/cancelar")
            ->assertStatus(422)
            ->assertJsonPath('errors.credito.0', 'No se puede cancelar ni eliminar una refinanciación desembolsada: el crédito original ya quedó congelado.');
        $this->deleteJson("/api/v1/creditos/{$refinanciacion->id}")->assertStatus(422)->assertJsonValidationErrors('credito');

        $this->assertSame(EstadoCredito::Desembolsado, $refinanciacion->fresh()->estado);
        $this->assertSame(EstadoCredito::Refinanciado, $origen->fresh()->estado);
    }

    public function test_no_se_puede_pedir_otra_refinanciacion_mientras_hay_una_en_curso(): void
    {
        $origen = $this->crearOrigen();
        $primera = $this->solicitarRefinanciacion($origen)->assertCreated()->json('credito.id');

        $this->solicitarRefinanciacion($origen)
            ->assertStatus(422)
            ->assertJsonPath('errors.credito_origen_id.0', 'Este crédito ya tiene una refinanciación en curso (solicitada o aprobada).');

        $this->postJson("/api/v1/creditos/{$primera}/cancelar")->assertOk();
        $this->solicitarRefinanciacion($origen)->assertCreated();
    }

    public function test_un_credito_refinanciado_no_recibe_pagos(): void
    {
        $origen = $this->refinanciar();

        $respuesta = $this->postJson("/api/v1/creditos/{$origen->id}/pagos", ['valor_pago' => 100, 'forma_pago' => 'efectivo']);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('estado');
        $respuesta->assertJsonPath('errors.estado.0', 'Este crédito fue refinanciado y quedó congelado; los pagos se aplican al crédito que lo reemplazó.');
        $this->assertDatabaseCount('pagos', 0);
    }

    public function test_un_credito_refinanciado_no_se_puede_liquidar(): void
    {
        $origen = $this->refinanciar();

        $this->postJson("/api/v1/creditos/{$origen->id}/liquidacion", ['forma_pago' => 'efectivo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');

        $this->assertDatabaseCount('pagos', 0);
        $this->assertSame(EstadoCredito::Refinanciado, $origen->fresh()->estado);
    }

    public function test_un_credito_refinanciado_no_se_puede_cancelar_ni_eliminar(): void
    {
        $origen = $this->refinanciar();

        $this->postJson("/api/v1/creditos/{$origen->id}/cancelar")->assertStatus(422)->assertJsonValidationErrors('estado');
        $this->deleteJson("/api/v1/creditos/{$origen->id}")->assertStatus(422)->assertJsonValidationErrors('credito');

        $this->assertSame(EstadoCredito::Refinanciado, $origen->fresh()->estado);
        $this->assertSame(2, $origen->cuotas()->count());
    }

    public function test_el_detalle_de_un_credito_refinanciado_no_lo_muestra_en_mora(): void
    {
        $origen = $this->refinanciar();
        $origen->cuotas()->where('numero_cuota', 2)->update(['estado' => EstadoCuota::Vencida]);

        $this->getJson("/api/v1/creditos/{$origen->id}")
            ->assertOk()
            ->assertJsonPath('cartera', 'Refinanciado')
            ->assertJsonPath('credito.estado', 'refinanciado');
    }

    public function test_solo_se_puede_refinanciar_un_credito_desembolsado(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();

        foreach ([EstadoCredito::Solicitado, EstadoCredito::Aprobado, EstadoCredito::Pagado, EstadoCredito::Cancelado, EstadoCredito::Refinanciado] as $estado) {
            $origen = $this->crearCredito($cliente, ['estado' => $estado]);

            $this->solicitarRefinanciacion($origen, $cliente)
                ->assertStatus(422)
                ->assertJsonPath('errors.credito_origen_id.0', 'Solo se puede refinanciar un crédito desembolsado.');

            $this->assertSame($estado, $origen->fresh()->estado, "El origen {$estado->value} no debe cambiar de estado.");
        }

        $this->assertDatabaseCount('creditos', 5);
    }

    public function test_la_mora_solo_marca_cuotas_de_creditos_desembolsados(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $vencida = now()->subDays(20)->toDateString();
        $cuotaPorEstado = [];

        foreach ([EstadoCredito::Desembolsado, EstadoCredito::Solicitado, EstadoCredito::Cancelado, EstadoCredito::Refinanciado] as $estado) {
            $credito = $this->crearCredito($cliente, ['estado' => $estado]);
            $cuotaPorEstado[$estado->value] = $this->crearCuota($credito, ['fecha_vencimiento' => $vencida]);
        }

        app(CuotaService::class)->actualizarVencidas();

        $this->assertSame(EstadoCuota::Vencida, $cuotaPorEstado['desembolsado']->fresh()->estado);

        foreach (['solicitado', 'cancelado', 'refinanciado'] as $estado) {
            $this->assertSame(EstadoCuota::Pendiente, $cuotaPorEstado[$estado]->fresh()->estado, "Un crédito {$estado} no entra en mora.");
        }
    }

    public function test_un_credito_refinanciado_no_aparece_en_la_cartera(): void
    {
        $origen = $this->refinanciar();
        $origen->cuotas()->where('numero_cuota', 2)->update(['fecha_vencimiento' => now()->subDays(40)->toDateString()]);

        $respuesta = $this->getJson('/api/v1/cartera')->assertOk();

        $creditosEnCartera = collect($respuesta->json('al_dia'))->merge($respuesta->json('en_mora'))->pluck('credito.id');
        $this->assertFalse($creditosEnCartera->contains($origen->id), 'El original refinanciado no cuenta en la cartera.');
        $this->assertTrue($creditosEnCartera->contains($this->refinanciacionDe($origen)->id), 'La refinanciación desembolsada sí.');
    }

    public function test_un_credito_refinanciado_ya_no_bloquea_la_baja_del_cliente(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $this->crearCredito($cliente, ['estado' => EstadoCredito::Refinanciado]);
        $this->crearCredito($cliente, ['estado' => EstadoCredito::Pagado]);
        $conActivo = $this->crearCliente();
        $this->crearCredito($conActivo, ['estado' => EstadoCredito::Refinanciado]);
        $this->crearCredito($conActivo, ['estado' => EstadoCredito::Desembolsado]);

        $this->deleteJson("/api/v1/clientes/{$conActivo->id}")->assertStatus(422)->assertJsonValidationErrors('cliente');
        $this->deleteJson("/api/v1/clientes/{$cliente->id}")->assertNoContent();
    }

    public function test_no_se_puede_refinanciar_un_credito_de_otra_empresa(): void
    {
        $this->actingAsAdmin($this->crearEmpresaDePrueba());
        $ajeno = $this->crearCredito($this->crearCliente());

        $this->actingAsAdmin();
        $cliente = $this->crearCliente();

        $this->solicitarRefinanciacion($ajeno, $cliente)->assertStatus(422)->assertJsonValidationErrors('credito_origen_id');

        $this->assertSame(EstadoCredito::Desembolsado, $ajeno->fresh()->estado);
    }
}
