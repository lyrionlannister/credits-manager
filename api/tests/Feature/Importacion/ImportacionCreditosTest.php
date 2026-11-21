<?php

namespace Tests\Feature\Importacion;

use App\Contabilidad\Enums\EstadoPeriodo;
use App\Contabilidad\Enums\EventoContable;
use App\Contabilidad\Models\PeriodoContable;
use App\Contabilidad\Models\PlanCuenta;
use App\Core\Dinero\Dinero;
use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Enums\TipoCredito;
use App\Creditos\Enums\TipoPago;
use App\Creditos\Models\Credito;
use App\Creditos\Models\Cuota;
use App\Creditos\Services\AmortizacionCalculator;
use App\Importacion\Enums\EstadoImportacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\CreatesCreditFixtures;
use Tests\Concerns\ImportaArchivos;
use Tests\TestCase;

class ImportacionCreditosTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesContabilidadFixtures;
    use CreatesCreditFixtures;
    use ImportaArchivos;
    use RefreshDatabase;

    private const ENCABEZADO_CREDITOS = "Código de crédito;Cédula cliente;Fecha de desembolso;Valor desembolsado (capital);Tasa de interés mensual (%);Plazo (# cuotas);Periodicidad (Mensual/Quincenal);Cuota fija (si ya la calcularon);Gastos administrativos (%);¿Es refinanciación? (Sí/No);Código del crédito anterior (si refinancia);Saldo refinanciado;Estado actual;Observaciones\n";

    private const ENCABEZADO_CLIENTES = "Cédula;Nombre completo;Teléfono;Correo electrónico;Empresa (nómina);Dirección;Barrio;Ciudad;Fecha de vínculo (si se conoce);Observaciones\n";

    private const ENCABEZADO_PAGOS = "Código de crédito;Fecha de pago;Valor pagado;Tipo (Cuota completa/Abono parcial);Número de cuota a la que aplica (si se sabe);Cuenta/caja receptora;Registrado por;Observaciones\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararImportaciones();
    }

    private function creditoCSV(string $codigo = 'CR-1', string $cedula = '1001', string $gastos = '0'): string
    {
        return self::ENCABEZADO_CREDITOS."{$codigo};{$cedula};01/10/2023;2000000;2,5;4;mensual;;{$gastos};No;;;;\n";
    }

    private function creditoConEaCSV(string $codigo, string $tasaMensual, string $tasaEfectivaAnual): string
    {
        return rtrim(self::ENCABEZADO_CREDITOS, "\n").";Tasa efectiva anual (%)\n".$this->filaConEa($codigo, $tasaMensual, $tasaEfectivaAnual);
    }

    private function filaConEa(string $codigo, string $tasaMensual, string $tasaEfectivaAnual): string
    {
        return "{$codigo};1001;01/10/2023;2000000;{$tasaMensual};4;mensual;;0;No;;;;;{$tasaEfectivaAnual}\n";
    }

    /** @return list<array{int, string, int, int, int}> */
    private function planDe(int $creditoId): array
    {
        return Cuota::query()->where('credito_id', $creditoId)->orderBy('numero_cuota')->get()
            ->map(fn (Cuota $cuota) => [$cuota->numero_cuota, $cuota->fecha_vencimiento->format('Y-m-d'), $cuota->valor_capital->centavos(), $cuota->valor_interes->centavos(), $cuota->valor->centavos()])
            ->all();
    }

    private function clienteCon(string $cedula = '1001'): void
    {
        $this->importarCompleto('clientes', self::ENCABEZADO_CLIENTES."{$cedula};Ana Pérez;;;;;;;;\n");
    }

    private function periodoAbierto(): void
    {
        PeriodoContable::create(['anio' => now()->year, 'mes' => now()->month, 'estado' => EstadoPeriodo::Abierto]);
    }

    private function reglaDesembolso(): array
    {
        $cartera = $this->crearCuenta(['codigo' => '133005', 'nombre' => 'Cartera de créditos']);
        $caja = $this->crearCuenta(['codigo' => '110505', 'nombre' => 'Caja']);
        $this->crearRegla(EventoContable::DesembolsoCredito, $cartera, $caja, $this->crearTipoComprobante());

        return [$cartera, $caja];
    }

    public function test_un_credito_importado_queda_desembolsado_con_su_plan_y_sin_asiento_por_defecto(): void
    {
        $this->actingAsAdmin();
        $this->clienteCon();

        $id = $this->importarCompleto('creditos', $this->creditoCSV());

        $this->assertSame(1, $this->importacion($id)->filas_aceptadas);
        $credito = Credito::query()->where('codigo', 'CR-1')->firstOrFail();
        $this->assertSame(EstadoCredito::Desembolsado, $credito->estado);
        $this->assertSame('2023-10-01', $credito->fecha_desembolso->toDateString());
        $this->assertSame(4, Cuota::query()->where('credito_id', $credito->id)->count());
        $this->assertDatabaseCount('comprobantes', 0);
    }

    public function test_un_credito_importado_guarda_el_porcentaje_de_otros_gastos_y_lo_aplica_a_las_cuotas(): void
    {
        $this->actingAsAdmin();
        $this->clienteCon();

        $id = $this->importarCompleto('creditos', $this->creditoCSV('CR-OG', '1001', '0,2'));

        $this->assertSame(1, $this->importacion($id)->filas_aceptadas);
        $credito = Credito::query()->where('codigo', 'CR-OG')->firstOrFail();

        $this->assertSame('0.200', $credito->gastos_administracion_pct);
        $this->assertSame(4, Cuota::query()->where('credito_id', $credito->id)->count());
        $this->assertTrue(Cuota::query()->where('credito_id', $credito->id)->get()->every(fn (Cuota $cuota) => $cuota->valor_otros_gastos->centavos() === 400000));
    }

    public function test_un_archivo_sin_la_columna_de_tasa_efectiva_anual_importa_con_la_mensual_y_sin_ea(): void
    {
        $this->actingAsAdmin();
        $this->clienteCon();

        $id = $this->importarCompleto('creditos', $this->creditoCSV());

        $this->assertSame(1, $this->importacion($id)->filas_aceptadas);
        $this->assertDatabaseHas('creditos', ['codigo' => 'CR-1', 'tasa_interes' => 2.5, 'tasa_efectiva_anual' => null]);
    }

    public function test_con_tasa_efectiva_anual_guarda_la_ea_la_mensual_derivada_y_el_mismo_plan_que_la_api(): void
    {
        $admin = $this->actingAsAdmin();
        $this->clienteCon();

        $id = $this->importarCompleto('creditos', $this->creditoConEaCSV('CR-EA', '', '28,59'));

        $this->assertSame(1, $this->importacion($id)->filas_aceptadas);
        $importado = Credito::query()->where('codigo', 'CR-EA')->firstOrFail();
        $mensual = AmortizacionCalculator::tasaMensualDesdeEfectivaAnual(28.59);
        $this->assertSame($admin->empresa_id, $importado->empresa_id);
        $this->assertSame('28.5900', $importado->tasa_efectiva_anual);
        $this->assertSame(number_format(round($mensual, 6), 6, '.', ''), $importado->tasa_interes);

        $creadoPorApi = $this->postJson('/api/v1/creditos', [
            'cliente_id' => $importado->cliente_id,
            'tipo_credito' => TipoCredito::Nuevo->value,
            'valor_prestamo' => 2000000,
            'tasa_efectiva_anual' => 28.59,
            'plazo' => 4,
            'tipo_pago' => TipoPago::Mensual->value,
            'fecha_desembolso' => '2023-10-01',
        ])->assertCreated()->json('credito.id');

        $this->assertSame($this->planDe($creadoPorApi), $this->planDe($importado->id));
        $this->assertSame(Credito::query()->findOrFail($creadoPorApi)->tasa_interes, $importado->tasa_interes);
    }

    public function test_una_tasa_efectiva_anual_en_cero_o_fuera_de_rango_rechaza_la_fila(): void
    {
        $this->actingAsAdmin();
        $this->clienteCon();

        $id = $this->importarCompleto('creditos', $this->creditoConEaCSV('CR-0', '', '0')
            .$this->filaConEa('CR-MAX', '', '1000'));

        $this->assertSame(0, $this->importacion($id)->filas_aceptadas);
        $rechazos = array_filter($this->erroresDe($id), fn (array $error) => $error['severidad'] === 'error');
        $this->assertCount(2, $rechazos);
        $this->assertSame(['tasa_efectiva_anual'], array_values(array_unique(array_column($rechazos, 'columna'))));
        $this->assertDatabaseCount('creditos', 0);
    }

    public function test_si_la_mensual_no_corresponde_a_la_ea_se_usa_la_ea_y_avisa(): void
    {
        $this->actingAsAdmin();
        $this->clienteCon();

        $id = $this->importarCompleto('creditos', $this->creditoConEaCSV('CR-INC', '2,5', '28,59')
            .$this->filaConEa('CR-OK', '2,12', '28,59'));

        $this->assertSame(2, $this->importacion($id)->filas_aceptadas);
        $avisosDeTasa = array_values(array_filter($this->erroresDe($id), fn (array $error) => $error['severidad'] === 'aviso' && $error['columna'] === 'tasa'));
        $this->assertCount(1, $avisosDeTasa);
        $this->assertSame(2, $avisosDeTasa[0]['fila']);
        $this->assertStringContainsString('se usa la efectiva anual', $avisosDeTasa[0]['motivo']);

        $mensual = number_format(round(AmortizacionCalculator::tasaMensualDesdeEfectivaAnual(28.59), 6), 6, '.', '');
        foreach (['CR-INC', 'CR-OK'] as $codigo) {
            $credito = Credito::query()->where('codigo', $codigo)->firstOrFail();
            $this->assertSame('28.5900', $credito->tasa_efectiva_anual);
            $this->assertSame($mensual, $credito->tasa_interes);
        }
    }

    public function test_el_aviso_de_cuota_solo_habla_de_redondeo_si_la_diferencia_es_de_centavos(): void
    {
        $this->actingAsAdmin();
        $this->clienteCon();
        $calculada = AmortizacionCalculator::calcular(Dinero::desdePesos(2000000), 2.5, 4, TipoPago::Mensual, '2023-10-01')['valor_cuota'];
        $redondeada = str_replace('.', ',', $calculada->sumar(Dinero::desdeCentavos(50))->aDecimalString());
        $distinta = str_replace('.', ',', $calculada->sumar(Dinero::desdePesos(5000))->aDecimalString());

        $id = $this->importarCompleto('creditos', self::ENCABEZADO_CREDITOS
            ."CR-R;1001;01/10/2023;2000000;2,5;4;mensual;{$redondeada};0;No;;;;\n"
            ."CR-D;1001;01/10/2023;2000000;2,5;4;mensual;{$distinta};0;No;;;;\n");

        $avisos = array_column(array_filter($this->erroresDe($id), fn (array $error) => $error['columna'] === 'cuota'), 'motivo', 'fila');
        $this->assertStringContainsString('(diferencia de redondeo)', $avisos[2]);
        $this->assertStringContainsString('(diferencia de 5000.00)', $avisos[3]);
        $this->assertStringNotContainsString('redondeo', $avisos[3]);
    }

    public function test_un_credito_de_un_cliente_de_otra_empresa_no_se_importa(): void
    {
        $this->actingAsAdmin();
        $this->clienteCon('1001');
        $this->actingAsAdmin();

        $id = $this->importarCompleto('creditos', $this->creditoCSV('CR-9', '1001'));

        $this->assertSame(0, $this->importacion($id)->filas_aceptadas);
        $this->assertStringContainsString('No existe un cliente con cédula 1001', implode(' ', $this->motivosDeRechazo($id)));
        $this->assertDatabaseCount('creditos', 0);
    }

    public function test_un_codigo_ya_importado_se_rechaza_al_resubir(): void
    {
        $this->actingAsAdmin();
        $this->clienteCon();
        $this->importarCompleto('creditos', $this->creditoCSV());

        $segunda = $this->importarCompleto('creditos', $this->creditoCSV());

        $this->assertStringContainsString('Ya existe un crédito', implode(' ', $this->motivosDeRechazo($segunda)));
        $this->assertDatabaseCount('creditos', 1);
    }

    public function test_refinanciaciones_no_se_importan_en_esta_carga(): void
    {
        $this->actingAsAdmin();
        $this->clienteCon();

        $id = $this->importarCompleto('creditos', self::ENCABEZADO_CREDITOS."CR-2;1001;01/10/2023;2000000;2,5;4;mensual;;0;Sí;CR-1;;;\n");

        $this->assertSame(0, $this->importacion($id)->filas_aceptadas);
        $this->assertStringContainsString('refinanciaciones', implode(' ', $this->motivosDeRechazo($id)));
    }

    public function test_aceptar_el_asiento_contabiliza_cada_desembolso_en_su_fecha_con_las_cuentas_de_la_regla(): void
    {
        $this->actingAsAdmin();
        $this->periodoAbierto();
        $this->clienteCon();
        [$cartera, $caja] = $this->reglaDesembolso();

        $id = $this->importarCompleto(
            'creditos',
            self::ENCABEZADO_CREDITOS."CR-1;1001;01/10/2023;2000000;2,5;4;mensual;;;;;;\nCR-3;1001;02/11/2023;1000000;2,5;4;mensual;;;;;;\n",
            ['generar_asiento' => true],
            'creditos.csv',
            ['generar_asiento' => true],
        );

        $this->assertSame(EstadoImportacion::Completada, $this->importacion($id)->estado);
        $this->assertDatabaseCount('comprobantes', 2);

        foreach (['CR-1' => ['2023-10-01', 200000000], 'CR-3' => ['2023-11-02', 100000000]] as $codigo => [$fecha, $valor]) {
            $credito = Credito::query()->where('codigo', $codigo)->firstOrFail();
            $comprobante = DB::table('comprobantes')->where('modulo_origen', 'creditos')->where('origen_id', $credito->id)->first();

            $this->assertNotNull($comprobante);
            $this->assertSame($fecha, substr((string) $comprobante->fecha, 0, 10));

            $lineas = DB::table('asientos_contables')->where('comprobante_id', $comprobante->id)->get();
            $this->assertTrue($lineas->contains(fn ($linea) => $linea->cuenta_id === $cartera->id && (int) $linea->debito === $valor));
            $this->assertTrue($lineas->contains(fn ($linea) => $linea->cuenta_id === $caja->id && (int) $linea->credito === $valor));
            $this->assertTrue($lineas->every(fn ($linea) => $linea->tercero_id === $credito->cliente->tercero_id));
        }
    }

    public function test_rechazar_el_asiento_importa_los_creditos_sin_asiento(): void
    {
        $this->actingAsAdmin();
        $this->periodoAbierto();
        $this->clienteCon();
        $this->reglaDesembolso();

        $id = $this->importarCompleto(
            'creditos',
            $this->creditoCSV(),
            ['generar_asiento' => false],
            'creditos.csv',
            ['generar_asiento' => true],
        );

        $this->assertSame(1, $this->importacion($id)->filas_aceptadas);
        $this->assertDatabaseCount('creditos', 1);
        $this->assertDatabaseCount('comprobantes', 0);
    }

    public function test_con_saldos_iniciales_de_la_empresa_no_genera_asiento_y_avisa(): void
    {
        $admin = $this->actingAsAdmin();
        $this->periodoAbierto();
        $this->clienteCon();
        $this->reglaDesembolso();
        $cuentaCartera = PlanCuenta::query()->where('codigo', '133005')->value('id');
        DB::table('saldos_apertura')->insert([
            'empresa_id' => $admin->empresa_id,
            'cuenta_id' => $cuentaCartera,
            'fecha_corte' => '2023-10-31',
            'debito' => 45000000,
            'credito' => 0,
            'creado_en' => now(),
            'ultima_actualizacion' => now(),
        ]);

        $id = $this->importarCompleto(
            'creditos',
            $this->creditoCSV(),
            ['generar_asiento' => true],
            'creditos.csv',
            ['generar_asiento' => true],
        );

        $this->assertDatabaseCount('comprobantes', 0);
        $this->assertStringContainsString('ya incluyen esta cartera', implode(' ', array_column($this->erroresDe($id), 'motivo')));
    }

    public function test_el_plan_del_archivo_reemplaza_la_cuota_y_recalcula_el_saldo_de_capital(): void
    {
        $this->actingAsAdmin();
        $this->clienteCon();
        $this->importarCompleto('creditos', $this->creditoCSV());

        $csv = "Código de crédito;Número de cuota;Fecha de vencimiento;Valor capital;Valor interés;Valor total cuota\n"
            ."CR-1;1;14/11/2023;450000;150000;600000\n";
        $id = $this->importarCompleto('plan_cuotas', $csv);

        $this->assertSame(1, $this->importacion($id)->filas_aceptadas);
        $cuota = Cuota::query()->whereHas('credito', fn ($q) => $q->where('codigo', 'CR-1'))->where('numero_cuota', 1)->firstOrFail();
        $this->assertSame(60000000, $cuota->valor->centavos());
        $this->assertSame('2023-11-14', $cuota->fecha_vencimiento->format('Y-m-d'));
        $this->assertSame(155000000, $cuota->saldo_capital->centavos());
    }

    public function test_el_plan_rechaza_filas_de_un_credito_no_importado_y_cuotas_que_no_suman(): void
    {
        $this->actingAsAdmin();
        $this->clienteCon();
        $this->importarCompleto('creditos', $this->creditoCSV());

        $csv = "Código de crédito;Número de cuota;Fecha de vencimiento;Valor capital;Valor interés;Valor total cuota\n"
            ."CR-X;1;14/11/2023;450000;150000;600000\n"
            ."CR-1;2;14/12/2023;450000;150000;610000\n";
        $id = $this->importarCompleto('plan_cuotas', $csv);

        $this->assertSame(0, $this->importacion($id)->filas_aceptadas);
        $motivos = implode(' ', $this->motivosDeRechazo($id));
        $this->assertStringContainsString('Crédito no importado', $motivos);
        $this->assertStringContainsString('no suman', $motivos);
    }

    public function test_un_pago_de_un_credito_inexistente_se_rechaza(): void
    {
        $this->actingAsAdmin();

        $id = $this->importarCompleto('pagos', self::ENCABEZADO_PAGOS."CR-NO;14/03/2023;280500;Cuota completa;;;;\n");

        $this->assertSame(0, $this->importacion($id)->filas_aceptadas);
        $this->assertStringContainsString('Crédito no importado', implode(' ', $this->motivosDeRechazo($id)));
        $this->assertDatabaseCount('pagos', 0);
    }

    public function test_un_pago_que_no_cuadra_con_el_plan_o_excede_el_saldo_se_rechaza(): void
    {
        $this->actingAsAdmin();
        $this->clienteCon();
        $this->importarCompleto('creditos', $this->creditoCSV());

        $id = $this->importarCompleto('pagos', self::ENCABEZADO_PAGOS
            ."CR-1;01/11/2023;123;Cuota completa;;;;\n"
            ."CR-1;01/11/2023;99999999;Abono parcial;;;;\n");

        $this->assertSame(0, $this->importacion($id)->filas_aceptadas);
        $motivos = implode(' ', $this->motivosDeRechazo($id));
        $this->assertStringContainsString('no coincide con el saldo de la cuota 1', $motivos);
        $this->assertStringContainsString('supera el saldo pendiente', $motivos);
        $this->assertDatabaseCount('pagos', 0);
    }

    public function test_un_pago_que_cuadra_se_registra_con_la_fecha_del_archivo_sin_recibo_ni_asiento(): void
    {
        $this->actingAsAdmin();
        $this->clienteCon();
        $this->importarCompleto('creditos', $this->creditoCSV());
        $valorCuota = Cuota::query()->whereHas('credito', fn ($q) => $q->where('codigo', 'CR-1'))->where('numero_cuota', 1)->firstOrFail()->valor->aDecimalString();

        $id = $this->importarCompleto('pagos', self::ENCABEZADO_PAGOS."CR-1;14/11/2023;{$valorCuota};Cuota completa;1;Bancolombia Ahorros;Asesor Juan;\n");

        $this->assertSame(1, $this->importacion($id)->filas_aceptadas);
        $pago = DB::table('pagos')->first();
        $this->assertSame('2023-11-14', substr((string) $pago->fecha_pago, 0, 10));
        $this->assertDatabaseCount('recibos_caja', 0);
        $this->assertDatabaseCount('comprobantes', 0);
        $cuota = Cuota::query()->whereHas('credito', fn ($q) => $q->where('codigo', 'CR-1'))->where('numero_cuota', 1)->firstOrFail();
        $this->assertSame('pagada', $cuota->estado->value);
    }
}
