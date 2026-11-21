<?php

namespace Tests\Feature\Importacion;

use App\Clientes\Models\Cliente;
use App\Contabilidad\Enums\EstadoPeriodo;
use App\Contabilidad\Enums\EventoContable;
use App\Contabilidad\Models\PeriodoContable;
use App\Creditos\Models\Cuota;
use App\Pagos\Models\Pago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\CreatesContabilidadFixtures;
use Tests\Concerns\ImportaArchivos;
use Tests\TestCase;

/** Reglas por fila de cada hoja, con datos falsos. */
class ImportacionReglasDeFilasTest extends TestCase
{
    use ActsAsAdmin;
    use CreatesContabilidadFixtures;
    use ImportaArchivos;
    use RefreshDatabase;

    private const CLIENTES = "Cédula;Nombre completo;Teléfono;Correo electrónico;Empresa (nómina);Dirección;Barrio;Ciudad;Fecha de vínculo (si se conoce);Observaciones\n";

    private const CREDITOS = "Código de crédito;Cédula cliente;Fecha de desembolso;Valor desembolsado (capital);Tasa de interés mensual (%);Plazo (# cuotas);Periodicidad (Mensual/Quincenal);Cuota fija (si ya la calcularon);¿Es refinanciación? (Sí/No);Código del crédito anterior (si refinancia);Saldo refinanciado;Estado actual;Observaciones\n";

    private const PLAN = "Código de crédito;Número de cuota;Fecha de vencimiento;Valor capital;Valor interés;Valor total cuota;Estado (Pendiente/Pagada/Vencida)\n";

    private const PAGOS = "Código de crédito;Fecha de pago;Valor pagado;Tipo (Cuota completa/Abono parcial);Número de cuota a la que aplica (si se sabe);Cuenta/caja receptora;Registrado por;Observaciones\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararImportaciones();
    }

    private function cliente(string $cedula = '1001'): void
    {
        $this->importarCompleto('clientes', self::CLIENTES."{$cedula};Ana Pérez;;;;;;;;\n");
    }

    private function credito(string $codigo = 'CR-1', string $cedula = '1001', string $cuotaFija = ''): void
    {
        $this->importarCompleto('creditos', self::CREDITOS."{$codigo};{$cedula};01/10/2023;2000000;2,5;4;mensual;{$cuotaFija};No;;;;\n");
    }

    /** Fila de ejemplo de la plantilla por hoja: se rechaza con su mensaje y no se escribe nada. */
    public function test_la_fila_de_ejemplo_de_cada_hoja_se_rechaza(): void
    {
        $this->actingAsAdmin();
        $this->cliente();
        $this->credito();

        $casos = [
            ['clientes', self::CLIENTES."53125435;Adriana Maria Daza Mesa;;;;;;;;\n"],
            ['creditos', self::CREDITOS."CR-2023-001;53125435;14/10/2023;2000000;2,5;4;mensual;;No;;;;\n"],
            ['plan_cuotas', self::PLAN."CR-2023-001;1;14/11/2023;255500;25000;280500;Pagada\n"],
            ['pagos', self::PAGOS."CR-2023-001;14/03/2023;280500;Cuota completa;1;Banco;Asesor;\n"],
            ['saldos_iniciales', "Fecha de corte,Cuenta PUC,Saldo débito,Saldo crédito\n2024-12-31,133005,45000000,0\n"],
        ];

        foreach ($casos as [$tipo, $csv]) {
            $id = $this->importarCompleto($tipo, $csv);
            $this->assertSame(0, $this->importacion($id)->filas_aceptadas, "La hoja {$tipo} aceptó la fila de ejemplo.");
            $this->assertStringContainsString('ejemplo de la plantilla', implode(' ', $this->motivosDeRechazo($id)), $tipo);
        }
    }

    public function test_una_periodicidad_distinta_de_mensual_o_quincenal_se_rechaza(): void
    {
        $this->actingAsAdmin();
        $this->cliente();

        $id = $this->importarCompleto('creditos', self::CREDITOS."CR-3;1001;01/10/2023;2000000;2,5;4;semanal;;No;;;;\n");

        $this->assertSame(0, $this->importacion($id)->filas_aceptadas);
        $this->assertStringContainsString('Periodicidad no reconocida', implode(' ', $this->motivosDeRechazo($id)));
    }

    public function test_quincenal_vence_el_15_y_el_ultimo_dia_del_mes(): void
    {
        $this->actingAsAdmin();
        $this->cliente();

        $this->importarCompleto('creditos', self::CREDITOS."CR-Q;1001;01/10/2023;1000000;2;3;Quincenal;;No;;;;\n");

        $fechas = Cuota::query()->whereHas('credito', fn ($q) => $q->where('codigo', 'CR-Q'))->orderBy('numero_cuota')->pluck('fecha_vencimiento')->map(fn ($f) => $f->format('Y-m-d'))->all();
        $this->assertSame(['2023-10-15', '2023-10-31', '2023-11-15'], $fechas);
    }

    public function test_un_credito_sin_cedula_se_rechaza_con_su_columna(): void
    {
        $this->actingAsAdmin();

        $id = $this->importarCompleto('creditos', self::CREDITOS."CR-4;;01/10/2023;2000000;2,5;4;mensual;;No;;;;\n");

        $this->assertSame(0, $this->importacion($id)->filas_aceptadas);
        $this->assertSame('Cédula cliente', $this->erroresDe($id)[0]['columna']);
    }

    public function test_la_cuota_fija_distinta_del_calculo_deja_un_aviso_y_se_usa_la_calculada(): void
    {
        $this->actingAsAdmin();
        $this->cliente();

        $id = $this->importarCompleto('creditos', self::CREDITOS."CR-5;1001;01/10/2023;2000000;2,5;4;mensual;999999;No;;;;\n");

        $this->assertSame(1, $this->importacion($id)->filas_aceptadas);
        $aviso = collect($this->erroresDe($id))->firstWhere('severidad', 'aviso');
        $this->assertNotNull($aviso);
        $this->assertStringContainsString('se usa la calculada (diferencia de 468363.24)', $aviso['motivo']);
        $this->assertSame(4, Cuota::query()->whereHas('credito', fn ($q) => $q->where('codigo', 'CR-5'))->count());
    }

    public function test_el_estado_actual_del_archivo_no_se_guarda_y_queda_como_aviso(): void
    {
        $this->actingAsAdmin();
        $this->cliente();

        $id = $this->importarCompleto('creditos', str_replace('mensual;;No;;;;', 'mensual;;No;;;Pagado;', self::CREDITOS."CR-6;1001;01/10/2023;2000000;2,5;4;mensual;;No;;;;\n"));

        $this->assertSame(1, $this->importacion($id)->filas_aceptadas);
        $this->assertStringContainsString('El estado se calcula con los pagos', implode(' ', array_column($this->erroresDe($id), 'motivo')));
    }

    public function test_un_cliente_sin_empresa_de_nomina_se_crea_sin_empleador(): void
    {
        $this->actingAsAdmin();

        $this->importarCompleto('clientes', self::CLIENTES."1001;Ana Pérez;;;;;;;;\n");

        $this->assertNull(Cliente::query()->value('empleador_id'));
        $this->assertDatabaseCount('empleadores', 0);
    }

    public function test_un_nombre_de_empleador_de_mas_de_150_caracteres_se_rechaza(): void
    {
        $this->actingAsAdmin();
        $nombre = str_repeat('E', 151);

        $id = $this->importarCompleto('clientes', self::CLIENTES."1001;Ana Pérez;;;{$nombre};;;;;\n");

        $this->assertSame(0, $this->importacion($id)->filas_aceptadas);
        $this->assertStringContainsString('150 caracteres', implode(' ', $this->motivosDeRechazo($id)));
    }

    public function test_un_plan_de_cuotas_para_un_credito_con_pagos_se_rechaza(): void
    {
        $this->actingAsAdmin();
        $this->cliente();
        $this->credito();
        $valorCuota = Cuota::query()->whereHas('credito', fn ($q) => $q->where('codigo', 'CR-1'))->where('numero_cuota', 1)->value('valor')->aDecimalString();
        $this->importarCompleto('pagos', self::PAGOS."CR-1;14/11/2023;{$valorCuota};Cuota completa;;;;\n");

        $id = $this->importarCompleto('plan_cuotas', self::PLAN."CR-1;1;14/11/2023;450000;150000;600000;\n");

        $this->assertSame(0, $this->importacion($id)->filas_aceptadas);
        $this->assertStringContainsString('ya tiene pagos', implode(' ', $this->motivosDeRechazo($id)));
    }

    public function test_una_cuota_que_no_existe_en_el_plan_generado_se_rechaza(): void
    {
        $this->actingAsAdmin();
        $this->cliente();
        $this->credito();

        $id = $this->importarCompleto('plan_cuotas', self::PLAN."CR-1;9;14/11/2023;450000;150000;600000;\n");

        $this->assertSame(0, $this->importacion($id)->filas_aceptadas);
        $this->assertStringContainsString('no tiene la cuota 9', implode(' ', $this->motivosDeRechazo($id)));
    }

    public function test_un_pago_sin_cuota_pendiente_se_rechaza_cuando_el_credito_ya_esta_saldado(): void
    {
        $this->actingAsAdmin();
        $this->cliente();
        $this->credito();
        $cuotas = Cuota::query()->whereHas('credito', fn ($q) => $q->where('codigo', 'CR-1'))->orderBy('numero_cuota')->get();
        $filas = '';
        foreach ($cuotas as $indice => $cuota) {
            $filas .= 'CR-1;'.sprintf('%02d/11/2023', 10 + $indice).";{$cuota->valor->aDecimalString()};Cuota completa;{$cuota->numero_cuota};;;\n";
        }
        $this->importarCompleto('pagos', self::PAGOS.$filas);
        $this->assertSame(0, Cuota::query()->whereHas('credito', fn ($q) => $q->where('codigo', 'CR-1'))->whereRaw('valor_pagado < valor')->count());

        $id = $this->importarCompleto('pagos', self::PAGOS."CR-1;15/11/2023;100;Abono parcial;;;;\n");

        $this->assertSame(0, $this->importacion($id)->filas_aceptadas);
        $this->assertStringContainsString('no tiene cuotas pendientes', implode(' ', $this->motivosDeRechazo($id)));
    }

    public function test_registrado_por_se_asocia_a_un_usuario_de_la_empresa_solo_si_coincide(): void
    {
        $admin = $this->actingAsAdmin();
        $this->cliente();
        $this->credito();
        $valorCuota = Cuota::query()->whereHas('credito', fn ($q) => $q->where('codigo', 'CR-1'))->where('numero_cuota', 1)->value('valor')->aDecimalString();

        $this->importarCompleto('pagos', self::PAGOS."CR-1;14/11/2023;{$valorCuota};Cuota completa;;Bancolombia;{$admin->nombre};\n");
        $this->importarCompleto('pagos', self::PAGOS."CR-1;14/12/2023;1;Abono parcial;;;Asesor Juan Ramírez;\n");

        $pagos = Pago::query()->orderBy('id')->get();
        $this->assertSame($admin->id, $pagos[0]->registrado_por);
        $this->assertNull($pagos[1]->registrado_por, 'Un nombre sin usuario no debe convertirse en usuario');
    }

    public function test_el_recaudo_se_contabiliza_balanceado_cuando_la_carga_lo_pide(): void
    {
        $this->actingAsAdmin();
        $this->cliente();
        $this->credito();
        $this->reglasDeRecaudo();
        PeriodoContable::create(['anio' => 2023, 'mes' => 11, 'estado' => EstadoPeriodo::Abierto]);
        $valorCuota = Cuota::query()->whereHas('credito', fn ($q) => $q->where('codigo', 'CR-1'))->where('numero_cuota', 1)->value('valor')->aDecimalString();

        $id = $this->importarCompleto('pagos', self::PAGOS."CR-1;14/11/2023;{$valorCuota};Cuota completa;;;;\n", ['generar_asiento' => true], 'pagos.csv', ['generar_asiento' => true]);

        $this->assertSame(1, $this->importacion($id)->filas_aceptadas);
        $this->assertDatabaseHas('comprobantes', ['modulo_origen' => 'pagos']);
        $lineas = DB::table('asientos_contables')->whereIn('comprobante_id', DB::table('comprobantes')->where('modulo_origen', 'pagos')->pluck('id'))->get();
        $this->assertSame((int) $lineas->sum('debito'), (int) $lineas->sum('credito'));
        $terceroId = Cliente::query()->value('tercero_id');
        $this->assertTrue($lineas->every(fn ($linea) => $linea->tercero_id === $terceroId));
    }

    public function test_con_el_periodo_cerrado_el_recaudo_rechaza_el_pago_y_no_deja_nada_a_medias(): void
    {
        $this->actingAsAdmin();
        $this->cliente();
        $this->credito();
        $this->reglasDeRecaudo();
        PeriodoContable::create(['anio' => 2023, 'mes' => 11, 'estado' => EstadoPeriodo::Cerrado]);
        $valorCuota = Cuota::query()->whereHas('credito', fn ($q) => $q->where('codigo', 'CR-1'))->where('numero_cuota', 1)->value('valor')->aDecimalString();

        $id = $this->importarCompleto('pagos', self::PAGOS."CR-1;14/11/2023;{$valorCuota};Cuota completa;;;;\n", ['generar_asiento' => true], 'pagos.csv', ['generar_asiento' => true]);

        $this->assertSame(0, $this->importacion($id)->filas_aceptadas);
        $this->assertSame('escritura', $this->erroresDe($id)[0]['fase']);
        $this->assertStringContainsString('cerrado', strtolower(implode(' ', array_column($this->erroresDe($id), 'motivo'))));
        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseCount('comprobantes', 0);
        $this->assertSame(0, Cuota::query()->whereHas('credito', fn ($q) => $q->where('codigo', 'CR-1'))->where('valor_pagado', '>', 0)->count());
    }

    public function test_una_cuenta_del_plan_que_no_existe_rechaza_la_fila_de_saldo(): void
    {
        $this->actingAsAdmin();

        $id = $this->importarCompleto('saldos_iniciales', "Fecha de corte,Cuenta PUC,Saldo débito,Saldo crédito\n2024-12-31,999999,100,0\n");

        $this->assertSame(0, $this->importacion($id)->filas_aceptadas);
        $this->assertStringContainsString('Cuenta no encontrada: 999999', implode(' ', $this->motivosDeRechazo($id)));
    }

    public function test_al_terminar_la_carga_se_notifica_al_usuario_que_la_subio(): void
    {
        $admin = $this->actingAsAdmin();

        $this->importarCompleto('clientes', self::CLIENTES."1001;Ana Pérez;;;;;;;;\n");

        $tipos = DB::table('notificaciones')->where('usuario_id', $admin->id)->pluck('tipo')->all();
        $this->assertContains('importacion_validada', $tipos);
        $this->assertContains('importacion_completada', $tipos);
    }

    private function reglasDeRecaudo(): void
    {
        $caja = $this->crearCuenta(['codigo' => '110505', 'nombre' => 'Caja']);
        $cartera = $this->crearCuenta(['codigo' => '133005', 'nombre' => 'Cartera de créditos']);
        $ingreso = $this->crearCuenta(['codigo' => '421005', 'nombre' => 'Intereses']);
        $tipo = $this->crearTipoComprobante();
        $this->crearRegla(EventoContable::RecaudoCapital, $caja, $cartera, $tipo);
        $this->crearRegla(EventoContable::RecaudoInteres, $caja, $ingreso, $tipo);
    }
}
