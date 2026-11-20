<?php

namespace Tests\Feature\Creditos;

use App\Creditos\Enums\TipoCredito;
use App\Creditos\Enums\TipoPago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

class CrearCreditoValidationTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    private function payloadValido(int $clienteId): array
    {
        return [
            'cliente_id' => $clienteId,
            'tipo_credito' => TipoCredito::Nuevo->value,
            'valor_prestamo' => 1000000,
            'tasa_interes' => 5,
            'plazo' => 12,
            'tipo_pago' => TipoPago::Mensual->value,
            'fecha_desembolso' => '2026-01-01',
        ];
    }

    /** En una refinanciación no se envía `valor_prestamo`: lo calcula el servidor a partir del original y del valor adicional. */
    private function payloadRefinanciacion(int $clienteId, array $cambios = []): array
    {
        $payload = $this->payloadValido($clienteId);
        unset($payload['valor_prestamo']);

        return array_merge($payload, [
            'tipo_credito' => TipoCredito::Refinanciacion->value,
            'valor_adicional' => 500000,
            'saldo_refinanciado_previsualizado' => 0,
        ], $cambios);
    }

    public function test_rechaza_cliente_inexistente(): void
    {
        $this->actingAsAdmin();

        $respuesta = $this->postJson('/api/v1/creditos', $this->payloadValido(999999));

        $respuesta->assertStatus(422)->assertJsonValidationErrors('cliente_id');
        $this->assertDatabaseCount('creditos', 0);
    }

    public function test_rechaza_valor_prestamo_cero_o_negativo(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();

        foreach ([0, -100] as $valorInvalido) {
            $respuesta = $this->postJson('/api/v1/creditos', [
                ...$this->payloadValido($cliente->id),
                'valor_prestamo' => $valorInvalido,
            ]);

            $respuesta->assertStatus(422)->assertJsonValidationErrors('valor_prestamo');

            // Protege la traducción (lang/es/validation.php), no solo que el campo falle.
            $respuesta->assertJsonPath('errors.valor_prestamo.0', 'El campo valor del préstamo debe ser al menos 0.01.');
        }

        $this->assertDatabaseCount('creditos', 0);
    }

    public function test_rechaza_primer_vencimiento_no_posterior_al_desembolso(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();

        foreach (['2026-01-01', '2025-12-20', '16/01/2026'] as $fecha) {
            $this->postJson('/api/v1/creditos', [...$this->payloadValido($cliente->id), 'fecha_primer_vencimiento' => $fecha])
                ->assertStatus(422)->assertJsonValidationErrors('fecha_primer_vencimiento');
        }

        $this->assertDatabaseCount('creditos', 0);
    }

    public function test_quincenal_exige_primer_vencimiento_el_15_o_fin_de_mes(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $quincenal = [...$this->payloadValido($cliente->id), 'tipo_pago' => TipoPago::Quincenal->value];

        $this->postJson('/api/v1/creditos', [...$quincenal, 'fecha_primer_vencimiento' => '2026-01-10'])
            ->assertStatus(422)->assertJsonValidationErrors('fecha_primer_vencimiento');
        $this->assertDatabaseCount('creditos', 0);

        $respuesta = $this->postJson('/api/v1/creditos', [...$quincenal, 'fecha_primer_vencimiento' => '2026-01-31'])->assertStatus(201);
        $fechas = DB::table('cuotas')->where('credito_id', $respuesta->json('credito.id'))->orderBy('numero_cuota')->limit(3)->pluck('fecha_vencimiento')->all();
        $this->assertSame(['2026-01-31', '2026-02-15', '2026-02-28'], $fechas);
    }

    public function test_con_primer_vencimiento_la_primera_cuota_cobra_el_interes_del_mes_completo(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();

        $respuesta = $this->postJson('/api/v1/creditos', [
            ...$this->payloadValido($cliente->id),
            'valor_prestamo' => 1000,
            'tasa_interes' => 10,
            'plazo' => 3,
            'fecha_primer_vencimiento' => '2026-01-16',
        ])->assertStatus(201);

        $primera = DB::table('cuotas')->where('credito_id', $respuesta->json('credito.id') ?? $respuesta->json('id'))->orderBy('numero_cuota')->first();
        $this->assertSame('2026-01-16', $primera->fecha_vencimiento);
        $this->assertSame(10000, (int) $primera->valor_interes);
    }

    public function test_rechaza_tasa_de_interes_negativa(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();

        $respuesta = $this->postJson('/api/v1/creditos', [
            ...$this->payloadValido($cliente->id),
            'tasa_interes' => -1,
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('tasa_interes');
    }

    public function test_rechaza_plazo_menor_a_uno(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();

        $respuesta = $this->postJson('/api/v1/creditos', [
            ...$this->payloadValido($cliente->id),
            'plazo' => 0,
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('plazo');
    }

    public function test_rechaza_tipo_de_pago_fuera_del_enum(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();

        $respuesta = $this->postJson('/api/v1/creditos', [
            ...$this->payloadValido($cliente->id),
            'tipo_pago' => 'semanal',
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('tipo_pago');
    }

    public function test_refinanciacion_exige_credito_origen(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();

        $respuesta = $this->postJson('/api/v1/creditos', $this->payloadRefinanciacion($cliente->id));

        $respuesta->assertStatus(422)->assertJsonValidationErrors('credito_origen_id');
    }

    /**
     * Mejora de seguridad sobre el legado (que solo validaba esto en JS):
     * no se puede refinanciar un crédito que pertenece a otro cliente.
     */
    public function test_refinanciacion_rechaza_credito_origen_de_otro_cliente(): void
    {
        $this->actingAsAdmin();
        $clienteA = $this->crearCliente();
        $clienteB = $this->crearCliente();
        $creditoDeB = $this->crearCredito($clienteB);

        $respuesta = $this->postJson('/api/v1/creditos', $this->payloadRefinanciacion($clienteA->id, ['credito_origen_id' => $creditoDeB->id]));

        $respuesta->assertStatus(422)->assertJsonValidationErrors('credito_origen_id');
        $respuesta->assertJsonPath('errors.credito_origen_id.0', 'El crédito seleccionado no pertenece a este cliente.');
        $this->assertDatabaseCount('creditos', 1);
        $this->assertSame('desembolsado', $creditoDeB->fresh()->estado->value);
    }

    public function test_refinanciacion_acepta_credito_origen_del_mismo_cliente(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $origen = $this->crearCredito($cliente);

        $respuesta = $this->postJson('/api/v1/creditos', $this->payloadRefinanciacion($cliente->id, [
            'credito_origen_id' => $origen->id,
            'saldo_refinanciado_previsualizado' => 900, // capital pendiente del origen: sin cuotas ni pagos, es su valor_prestamo completo.
        ]));

        $respuesta->assertStatus(201);
        $this->assertSame('desembolsado', $origen->fresh()->estado->value, 'El original se congela al desembolsar la refinanciación, no al solicitarla.');
    }

    public function test_sin_autenticar_no_puede_crear_creditos(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $this->salirDeSesion();

        $respuesta = $this->postJson('/api/v1/creditos', $this->payloadValido($cliente->id));

        $respuesta->assertStatus(401);
    }

    public function test_asesor_sin_permiso_de_creditos_no_puede_crear(): void
    {
        $this->actingAsAsesor();
        $cliente = $this->crearCliente();

        $respuesta = $this->postJson('/api/v1/creditos', $this->payloadValido($cliente->id));

        $respuesta->assertStatus(403);
        $this->assertDatabaseCount('creditos', 0);
    }

    /** Tabla de DDA: 28,59 % EA es 2,1176 % mensual; la cuota sale de la mensual exacta, no de la redondeada. */
    public function test_con_tasa_efectiva_anual_guarda_la_ea_y_la_mensual_derivada(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $payload = [...$this->payloadValido($cliente->id), 'tasa_efectiva_anual' => 28.59];
        unset($payload['tasa_interes']);

        $this->postJson('/api/v1/creditos', $payload)->assertCreated();

        $this->assertDatabaseHas('creditos', ['cliente_id' => $cliente->id, 'tasa_efectiva_anual' => 28.59, 'tasa_interes' => 2.1176]);
    }

    public function test_rechaza_tasa_efectiva_anual_y_tasa_mensual_a_la_vez_o_ninguna(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();

        $this->postJson('/api/v1/creditos', [...$this->payloadValido($cliente->id), 'tasa_efectiva_anual' => 28.59])
            ->assertStatus(422)->assertJsonValidationErrors('tasa_efectiva_anual');

        $sinTasa = $this->payloadValido($cliente->id);
        unset($sinTasa['tasa_interes']);

        $this->postJson('/api/v1/creditos', $sinTasa)
            ->assertStatus(422)->assertJsonValidationErrors(['tasa_efectiva_anual', 'tasa_interes']);
        $this->assertDatabaseCount('creditos', 0);
    }
}
