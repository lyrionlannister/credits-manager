<?php

namespace Tests\Feature\Calendario;

use App\Core\Dinero\Dinero;
use App\Pagos\Enums\FormaPago;
use App\Pagos\Services\PagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\TestCase;

class CalendarioControllerTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesContabilidadFixtures;
    use CreatesCreditFixtures;
    use RefreshDatabase;

    public function test_devuelve_las_cuotas_del_mes_solicitado_con_su_color(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente);

        // El mes objetivo debe ser futuro de verdad: si cayera en el pasado,
        // `actualizarVencidas()` pasaría la cuota "pendiente" a "vencida" antes de
        // pintarla, y dejaría de haber ninguna cuota realmente en warning que probar.
        $mesObjetivo = now()->addMonths(2);
        $otroMes = now()->addMonths(3);

        $pagada = $this->crearCuota($credito, ['numero_cuota' => 1, 'fecha_vencimiento' => $mesObjetivo->copy()->day(5), 'valor' => 100, 'valor_pagado' => 100, 'estado' => 'pagada']);
        $vencida = $this->crearCuota($credito, ['numero_cuota' => 2, 'fecha_vencimiento' => $mesObjetivo->copy()->day(10), 'estado' => 'vencida']);
        $pendiente = $this->crearCuota($credito, ['numero_cuota' => 3, 'fecha_vencimiento' => $mesObjetivo->copy()->day(20), 'estado' => 'pendiente']);
        $this->crearCuota($credito, ['numero_cuota' => 4, 'fecha_vencimiento' => $otroMes->copy()->day(1), 'estado' => 'pendiente']);

        $respuesta = $this->getJson('/api/v1/calendario?mes='.$mesObjetivo->format('Y-m'));

        $respuesta->assertStatus(200);
        $cuotas = collect($respuesta->json('cuotas'))->keyBy('id');

        $this->assertCount(3, $cuotas);
        $this->assertSame('success', $cuotas[$pagada->id]['color']);
        $this->assertSame('danger', $cuotas[$vencida->id]['color']);
        $this->assertSame('warning', $cuotas[$pendiente->id]['color']);
    }

    public function test_la_cuota_trae_el_cliente_y_los_creditos_con_la_entidad_sin_cancelados_ni_eliminados(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente(['numero_documento' => '1234567', 'celular' => '3001112233']);
        $activo = $this->crearCredito($cliente);
        $cancelado = $this->crearCredito($cliente, ['estado' => 'cancelado']);
        $eliminado = $this->crearCredito($cliente);
        $eliminado->delete();

        $mesObjetivo = now()->addMonths(2);
        $cuota = $this->crearCuota($activo, ['numero_cuota' => 1, 'fecha_vencimiento' => $mesObjetivo->copy()->day(5), 'estado' => 'pendiente']);

        $cancelado->update(['estado' => 'cancelado']);

        $respuesta = $this->getJson('/api/v1/calendario?mes='.$mesObjetivo->format('Y-m'));

        $respuesta->assertStatus(200);
        $fila = collect($respuesta->json('cuotas'))->firstWhere('id', $cuota->id);
        $this->assertSame($cliente->id, $fila['cliente']['id']);
        $this->assertSame('1234567', $fila['cliente']['numero_documento']);
        $this->assertSame('3001112233', $fila['cliente']['celular']);
        $this->assertSame(1, $fila['creditos_con_entidad']);
    }

    public function test_sin_mes_usa_el_mes_actual(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente);
        $this->crearCuota($credito, ['numero_cuota' => 1, 'fecha_vencimiento' => now()->startOfMonth()->addDays(2)->toDateString()]);
        $this->crearCuota($credito, ['numero_cuota' => 2, 'fecha_vencimiento' => now()->addMonths(2)->toDateString()]);

        $respuesta = $this->getJson('/api/v1/calendario');

        $respuesta->assertStatus(200);
        $this->assertCount(1, $respuesta->json('cuotas'));
    }

    public function test_rechaza_un_formato_de_mes_invalido(): void
    {
        $this->actingAsAdmin();

        $respuesta = $this->getJson('/api/v1/calendario?mes=mayo-2026');

        $respuesta->assertStatus(422)->assertJsonValidationErrors('mes');
    }

    public function test_no_incluye_cuotas_de_creditos_no_desembolsados(): void
    {
        $this->actingAsAdmin();
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, ['estado' => 'solicitado']);
        $this->crearCuota($credito, ['numero_cuota' => 1, 'fecha_vencimiento' => '2026-05-05']);

        $respuesta = $this->getJson('/api/v1/calendario?mes=2026-05');

        $respuesta->assertStatus(200);
        $this->assertCount(0, $respuesta->json('cuotas'));
    }

    public function test_filtra_por_empleador(): void
    {
        $this->actingAsAdmin();
        $empleadorA = $this->crearEmpleador(['nombre' => 'Empleador A']);
        $empleadorB = $this->crearEmpleador(['nombre' => 'Empleador B']);
        $clienteA = $this->crearCliente(['empleador_id' => $empleadorA->id]);
        $clienteB = $this->crearCliente(['empleador_id' => $empleadorB->id]);

        $mesObjetivo = now()->addMonths(2);
        $cuotaA = $this->crearCuota($this->crearCredito($clienteA), ['numero_cuota' => 1, 'fecha_vencimiento' => $mesObjetivo->copy()->day(5)]);
        $this->crearCuota($this->crearCredito($clienteB), ['numero_cuota' => 1, 'fecha_vencimiento' => $mesObjetivo->copy()->day(10)]);

        $respuesta = $this->getJson('/api/v1/calendario?mes='.$mesObjetivo->format('Y-m').'&empleador_id='.$empleadorA->id);

        $respuesta->assertStatus(200);
        $this->assertSame([$cuotaA->id], collect($respuesta->json('cuotas'))->pluck('id')->all());
    }

    public function test_incluye_los_empleadores_con_clientes_para_el_selector(): void
    {
        $this->actingAsAdmin();
        $empleadorConCliente = $this->crearEmpleador(['nombre' => 'Empleador con cliente']);
        $this->crearEmpleador(['nombre' => 'Empleador sin clientes']);
        $this->crearCliente(['empleador_id' => $empleadorConCliente->id]);

        $respuesta = $this->getJson('/api/v1/calendario');

        $respuesta->assertStatus(200);
        $this->assertSame([$empleadorConCliente->id], collect($respuesta->json('empleadores'))->pluck('id')->all());
    }

    public function test_incluye_cuotas_pagadas_desde_pagoservice_con_color_success(): void
    {
        $this->actingAsAdmin();
        $this->crearReglasDeCreditoYPago();
        $cliente = $this->crearCliente();
        $credito = $this->crearCredito($cliente, ['valor_prestamo' => 100]);
        $cuota = $this->crearCuota($credito, [
            'numero_cuota' => 1,
            'fecha_vencimiento' => now()->toDateString(),
            'valor' => 100,
            'valor_capital' => 100,
        ]);

        app(PagoService::class)->registrarPago($credito->fresh(), Dinero::desdePesos(100), FormaPago::Efectivo, null, null);

        $mesActual = now()->format('Y-m');
        $respuesta = $this->getJson("/api/v1/calendario?mes={$mesActual}");

        $respuesta->assertStatus(200);
        $cuotas = collect($respuesta->json('cuotas'))->keyBy('id');
        $this->assertSame('success', $cuotas[$cuota->id]['color']);
    }
}
