<?php

namespace Tests\Feature\Pagos;

use App\Contabilidad\Enums\EventoContable;
use App\Contabilidad\Models\Comprobante;
use App\Core\Dinero\Dinero;
use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Models\Credito;
use App\Empresas\Models\Empresa;
use App\Pagos\Enums\FormaPago;
use App\Pagos\Models\Pago;
use App\Pagos\Services\PagoService;
use App\Usuarios\Models\Rol;
use App\Usuarios\Models\Usuario;
use Database\Seeders\PermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\Concerns\CreatesFacturacionFixtures;
use Tests\TestCase;

class ReversarPagoTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesContabilidadFixtures;
    use CreatesCreditFixtures;
    use CreatesFacturacionFixtures;
    use RefreshDatabase;

    private const MOTIVO = 'Cheque devuelto por el banco';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermisoSeeder::class);
    }

    private function configurarReglas(): void
    {
        $cuentaBancos = $this->crearCuenta();
        $cuentaCartera = $this->crearCuenta();
        $cuentaIngresosIntereses = $this->crearCuenta();
        $tipo = $this->crearTipoComprobante();

        $this->crearRegla(EventoContable::RecaudoCapital, $cuentaBancos, $cuentaCartera, $tipo);
        $this->crearRegla(EventoContable::RecaudoInteres, $cuentaBancos, $cuentaIngresosIntereses, $tipo);
    }

    /** Crédito de 240 en tres cuotas de 100 (20 de interés, 80 de capital) con vencimientos futuros. */
    private function creditoConTresCuotas(): Credito
    {
        $credito = $this->crearCredito($this->crearCliente(), ['valor_prestamo' => 240, 'valor_cuota' => 100, 'valor_total' => 300]);

        foreach ([1, 2, 3] as $numero) {
            $this->crearCuota($credito, [
                'numero_cuota' => $numero,
                'fecha_vencimiento' => now()->addMonths($numero)->toDateString(),
                'valor' => 100,
                'valor_interes' => 20,
                'valor_capital' => 80,
            ]);
        }

        return $credito->fresh();
    }

    private function pagar(Credito $credito, float $valor): Pago
    {
        $this->crearReglasDeCreditoYPago();

        return app(PagoService::class)->registrarPago($credito->fresh(), Dinero::desdePesos($valor), FormaPago::Efectivo, null, null)['pago'];
    }

    private function urlReversa(Pago $pago): string
    {
        return "/api/v1/pagos/{$pago->id}/reversar";
    }

    public function test_sin_autenticar_devuelve_401(): void
    {
        $this->actingAsAdmin();
        $pago = $this->pagar($this->creditoConTresCuotas(), 100);
        $this->salirDeSesion();

        $this->postJson($this->urlReversa($pago), ['motivo' => self::MOTIVO])->assertStatus(401);
    }

    public function test_asesor_sin_permiso_de_reversa_recibe_403(): void
    {
        $admin = $this->actingAsAdmin();
        $asesor = $this->actingAsAsesor(Empresa::findOrFail($admin->empresa_id));

        // El cliente es del asesor: si no lo viera, el alcance por asesor respondería 404 antes que el permiso.
        $credito = $this->crearCredito($this->crearCliente(['asesor_id' => $asesor->id]), ['valor_prestamo' => 240, 'valor_cuota' => 100, 'valor_total' => 300, 'plazo' => 3]);
        $this->crearCuota($credito, ['numero_cuota' => 1, 'fecha_vencimiento' => now()->addMonth()->toDateString(), 'valor' => 100, 'valor_interes' => 20, 'valor_capital' => 80]);
        $pago = $this->pagar($credito, 100);

        $this->postJson($this->urlReversa($pago), ['motivo' => self::MOTIVO])->assertStatus(403);
        $this->assertDatabaseMissing('reversas_pago', ['pago_id' => $pago->id]);
    }

    public function test_pago_inexistente_o_de_otra_empresa_responde_404(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/pagos/999999/reversar', ['motivo' => self::MOTIVO])->assertStatus(404);

        $pagoAjeno = $this->pagar($this->creditoConTresCuotas(), 100);
        $this->actingAsAdmin();

        $this->postJson($this->urlReversa($pagoAjeno), ['motivo' => self::MOTIVO])->assertStatus(404);
        $this->assertDatabaseMissing('reversas_pago', ['pago_id' => $pagoAjeno->id]);
    }

    public function test_motivo_es_obligatorio_y_tiene_minimo_de_caracteres(): void
    {
        $this->actingAsAdmin();
        $pago = $this->pagar($this->creditoConTresCuotas(), 100);

        $this->postJson($this->urlReversa($pago), [])->assertStatus(422)->assertJsonValidationErrors(['motivo']);
        $this->postJson($this->urlReversa($pago), ['motivo' => 'abc'])->assertStatus(422)->assertJsonValidationErrors(['motivo']);
        $this->assertDatabaseMissing('reversas_pago', ['pago_id' => $pago->id]);
    }

    public function test_reversa_restaura_las_cuotas_anula_el_recibo_y_su_asiento_cuadra(): void
    {
        $this->actingAsAdmin();
        $this->configurarReglas();
        $credito = $this->creditoConTresCuotas();
        $pago = $this->pagar($credito, 150);

        $this->postJson($this->urlReversa($pago), ['motivo' => self::MOTIVO])->assertOk();

        // Las dos cuotas afectadas vuelven a su saldo anterior y el capital pendiente vuelve a 240.
        foreach ([1, 2] as $numero) {
            $cuota = $credito->cuotas()->where('numero_cuota', $numero)->firstOrFail();
            $this->assertEquals(0, $cuota->valor_pagado->aFloat(), "cuota {$numero}");
            $this->assertEquals(EstadoCuota::Pendiente, $cuota->estado);
            $this->assertEquals(240, $cuota->saldo_capital->aFloat(), "saldo de la cuota {$numero}");
        }
        $this->assertEquals(EstadoCredito::Desembolsado, $credito->fresh()->estado);

        // El pago y su detalle no se borran: la reversa es un movimiento compensatorio.
        $this->assertDatabaseHas('pagos', ['id' => $pago->id]);
        $this->assertDatabaseCount('pago_detalle', 2);

        $this->assertDatabaseHas('reversas_pago', [
            'pago_id' => $pago->id,
            'valor' => 15000,
            'motivo' => self::MOTIVO,
        ]);

        // El recibo queda anulado y conserva su número.
        $recibo = $pago->recibo()->firstOrFail();
        $this->assertEquals('anulado', $recibo->estado->value);
        $this->assertNotNull($recibo->numero);
        $this->assertEquals(self::MOTIVO, $recibo->motivo_anulacion);

        // El comprobante de recaudo queda anulado y su reverso lo cancela línea por línea.
        $original = Comprobante::where('modulo_origen', 'pagos')->where('origen_id', $pago->id)->firstOrFail();
        $reverso = Comprobante::where('modulo_origen', 'reverso')->where('origen_id', $original->id)->firstOrFail();

        $this->assertEquals('anulado', $original->estado->value);
        $this->assertEquals($reverso->id, $original->comprobante_reverso_id);
        $this->assertEquals('contabilizado', $reverso->estado->value);
        $this->assertTrue($original->total_debito->igualA($original->total_credito));
        $this->assertTrue($reverso->total_debito->igualA($reverso->total_credito));
        $this->assertTrue($reverso->total_debito->igualA($original->total_credito));
        $this->assertTrue($reverso->total_credito->igualA($original->total_debito));
    }

    public function test_reversar_el_pago_que_salda_el_credito_lo_devuelve_a_desembolsado(): void
    {
        $this->actingAsAdmin();
        $credito = $this->creditoConTresCuotas();
        $pago = $this->pagar($credito, 300);

        $this->assertEquals(EstadoCredito::Pagado, $credito->fresh()->estado);

        $this->postJson($this->urlReversa($pago), ['motivo' => self::MOTIVO])->assertOk();

        $this->assertEquals(EstadoCredito::Desembolsado, $credito->fresh()->estado);
        $this->assertEquals(0, $credito->cuotas()->sum('valor_pagado') / 100);
    }

    public function test_no_reversa_un_pago_anterior_al_ultimo_aplicado(): void
    {
        $this->actingAsAdmin();
        $credito = $this->creditoConTresCuotas();
        $anterior = $this->pagar($credito, 100);
        $this->pagar($credito, 50);

        $this->postJson($this->urlReversa($anterior), ['motivo' => self::MOTIVO])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pago']);

        $this->assertDatabaseMissing('reversas_pago', ['pago_id' => $anterior->id]);
    }

    public function test_un_pago_reversado_no_se_reversa_otra_vez(): void
    {
        $this->actingAsAdmin();
        $pago = $this->pagar($this->creditoConTresCuotas(), 100);

        $this->postJson($this->urlReversa($pago), ['motivo' => self::MOTIVO])->assertOk();

        $this->postJson($this->urlReversa($pago), ['motivo' => self::MOTIVO])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pago']);
        $this->assertDatabaseCount('reversas_pago', 1);
    }

    public function test_reversar_una_liquidacion_devuelve_cada_cuota_a_lo_que_le_aplicaron_los_demas_pagos(): void
    {
        $this->actingAsAdmin();
        $this->configurarReglas();
        $credito = $this->creditoConTresCuotas();
        $this->pagar($credito, 150);
        $liquidacion = app(PagoService::class)->liquidarCredito($credito->fresh(), FormaPago::Efectivo, null, null)['pago'];

        $this->assertEquals(EstadoCredito::Pagado, $credito->fresh()->estado);

        $this->postJson($this->urlReversa($liquidacion), ['motivo' => self::MOTIVO])->assertOk();

        $esperado = [1 => [100, EstadoCuota::Pagada], 2 => [50, EstadoCuota::Pendiente], 3 => [0, EstadoCuota::Pendiente]];
        foreach ($esperado as $numero => [$pagado, $estado]) {
            $cuota = $credito->cuotas()->where('numero_cuota', $numero)->firstOrFail();
            $this->assertEquals($pagado, $cuota->valor_pagado->aFloat(), "cuota {$numero}");
            $this->assertEquals($estado, $cuota->estado, "estado de la cuota {$numero}");
        }

        $this->assertEquals(EstadoCredito::Desembolsado, $credito->fresh()->estado);
        $this->assertDatabaseHas('reversas_pago', ['pago_id' => $liquidacion->id, 'motivo' => self::MOTIVO]);
        $this->assertEquals('anulado', $liquidacion->recibo()->firstOrFail()->estado->value);

        $original = Comprobante::where('modulo_origen', 'pagos')->where('origen_id', $liquidacion->id)->firstOrFail();
        $this->assertEquals('anulado', $original->estado->value);
        $this->assertNotNull($original->comprobante_reverso_id);
    }

    public function test_reversar_una_liquidacion_sin_pagos_previos_deja_todas_las_cuotas_en_cero(): void
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $credito = $this->creditoConTresCuotas();
        $liquidacion = app(PagoService::class)->liquidarCredito($credito, FormaPago::Efectivo, null, null)['pago'];

        $this->postJson($this->urlReversa($liquidacion), ['motivo' => self::MOTIVO])->assertOk();

        $this->assertEquals(0, $credito->cuotas()->sum('valor_pagado'));
        $this->assertEquals(0, $credito->cuotas()->where('estado', EstadoCuota::Pagada)->count());
        $this->assertEquals(EstadoCredito::Desembolsado, $credito->fresh()->estado);
    }

    public function test_reversada_la_liquidacion_se_puede_volver_a_liquidar_con_la_fecha_real(): void
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $credito = $this->crearCredito($this->crearCliente(), [
            'valor_prestamo' => 1_000_000,
            'tasa_interes' => 2,
            'fecha_desembolso' => now()->subDays(60)->toDateString(),
        ]);
        $this->crearCuota($credito, ['numero_cuota' => 1, 'fecha_vencimiento' => now()->addMonth()->toDateString(), 'valor' => 1_020_000, 'valor_capital' => 1_000_000, 'valor_interes' => 20_000]);
        $liquidacion = app(PagoService::class)->liquidarCredito($credito->fresh(), FormaPago::Efectivo, null, null)['pago'];

        $this->postJson($this->urlReversa($liquidacion), ['motivo' => self::MOTIVO])->assertOk();

        $fechaReal = now('America/Bogota')->subDays(30)->toDateString();
        $respuesta = $this->postJson("/api/v1/creditos/{$credito->id}/liquidacion", ['forma_pago' => 'transferencia', 'fecha_pago' => $fechaReal]);

        // 30 días desde el desembolso al 2 % mensual: el interés de la reliquidación es 20.000, no el de 60 días.
        $respuesta->assertStatus(201)->assertJsonPath('dias_transcurridos', 30);
        $nueva = Pago::findOrFail($respuesta->json('pago.id'));
        $this->assertSame('1020000.00', $nueva->valor_pago->aDecimalString());
        $this->assertEquals($fechaReal, $nueva->fecha_pago->toDateString());
        $this->assertEquals(EstadoCredito::Pagado, $credito->fresh()->estado);
    }

    public function test_contable_con_el_permiso_de_reversa_puede_reversar(): void
    {
        $admin = $this->actingAsAdmin();
        $pago = $this->pagar($this->creditoConTresCuotas(), 100);

        $rol = Rol::create(['empresa_id' => $admin->empresa_id, 'nombre' => 'contable']);
        $contable = Usuario::create([
            'empresa_id' => $admin->empresa_id,
            'nombre' => 'Contable de prueba',
            'usuario' => 'contable_test_'.uniqid(),
            'password_hash' => 'irrelevante-en-tests',
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
        DB::table('rol_permiso')->insert([
            'rol_id' => $rol->id,
            'permiso_id' => DB::table('permisos')->where('modulo', 'pagos')->where('accion', 'reversar')->value('id'),
            'creado_en' => now(),
            'ultima_actualizacion' => now(),
        ]);

        $this->actingAs($contable, 'sanctum');

        $this->postJson($this->urlReversa($pago), ['motivo' => self::MOTIVO])->assertOk();
        $this->assertDatabaseHas('reversas_pago', ['pago_id' => $pago->id]);
    }

    public function test_no_se_reversa_un_pago_cuyo_interes_ya_se_facturo(): void
    {
        $this->actingAsAdmin();
        $this->crearReglasFacturacion();
        $credito = $this->creditoConTresCuotas();
        $pago = $this->pagar($credito, 100);

        $cuota = $credito->cuotas()->where('numero_cuota', 1)->firstOrFail();
        $this->postJson('/api/v1/facturacion/documentos/intereses-pagados', [
            'credito_id' => $credito->id,
            'cuota_ids' => [$cuota->id],
        ])->assertStatus(201);

        $this->postJson($this->urlReversa($pago), ['motivo' => self::MOTIVO])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pago']);
        $this->assertDatabaseMissing('reversas_pago', ['pago_id' => $pago->id]);
    }

    public function test_el_recibo_anulado_sigue_descargable_como_pdf(): void
    {
        $this->actingAsAdmin();
        $pago = $this->pagar($this->creditoConTresCuotas(), 100);
        $this->postJson($this->urlReversa($pago), ['motivo' => self::MOTIVO])->assertOk();

        $respuesta = $this->get("/api/v1/pagos/{$pago->id}/recibo/pdf");

        $respuesta->assertOk();
        $this->assertStringStartsWith('application/pdf', $respuesta->headers->get('Content-Type'));
    }
}
