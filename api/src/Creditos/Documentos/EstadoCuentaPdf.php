<?php

namespace App\Creditos\Documentos;

use App\Core\Dinero\Dinero;
use App\Creditos\Enums\EstadoCuota;
use App\Creditos\Models\Credito;
use App\Impresion\Contracts\DocumentoPdf;
use App\Impresion\Data\CamposEmpresaDocumento;
use App\Impresion\Data\ColumnaDocumento;
use App\Impresion\Data\DestinatarioDocumento;
use App\Impresion\Data\FechaDocumento;
use App\Impresion\Data\SeccionDocumento;
use App\Impresion\Data\TablaDocumento;
use App\Impresion\Data\TotalDocumento;
use App\Impresion\Enums\OrientacionDocumento;
use App\Impresion\Enums\TipoColumnaDocumento;
use App\Impresion\Services\FormateadorDocumento;
use App\Pagos\Models\Pago;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Estado de cuenta de un crédito: plan de cuotas, pagos y saldo. Arma el documento con los datos que ya calculó `EstadoCuentaService`. */
final class EstadoCuentaPdf implements DocumentoPdf
{
    /**
     * @param  array<string, mixed>|null  $refinanciacion  vínculo de `CreditoService::vinculoRefinanciacion`
     * @param  Collection<int, Pago>  $pagos
     * @param  array<int, Dinero>  $saldoCapitalTeoricoPorCuota  por número de cuota
     */
    public function __construct(
        private readonly Credito $credito,
        private readonly Dinero $saldo,
        private readonly ?array $refinanciacion,
        private readonly Collection $pagos,
        private readonly array $saldoCapitalTeoricoPorCuota,
        private readonly CarbonInterface $generadoEn,
        private readonly FormateadorDocumento $formateador = new FormateadorDocumento,
    ) {}

    public function titulo(): string
    {
        return 'Estado de cuenta del crédito';
    }

    public function consecutivo(): ?string
    {
        return (string) $this->credito->id;
    }

    public function nombreArchivo(): string
    {
        return "estado-de-cuenta-credito-{$this->credito->id}";
    }

    public function orientacion(): OrientacionDocumento
    {
        return OrientacionDocumento::Vertical;
    }

    public function destinatario(): DestinatarioDocumento
    {
        $tercero = $this->credito->cliente->tercero;

        return DestinatarioDocumento::desdeTercero($tercero);
    }

    public function fechas(): array
    {
        $fechas = [new FechaDocumento('Fecha de corte', $this->generadoEn)];

        if ($this->credito->fecha_desembolso) {
            $fechas[] = new FechaDocumento('Desembolso', $this->credito->fecha_desembolso);
        }

        return $fechas;
    }

    public function tituloTabla(): ?string
    {
        return 'Plan de cuotas';
    }

    public function columnas(): array
    {
        return [
            new ColumnaDocumento('numero', '#', TipoColumnaDocumento::Entero, 4),
            new ColumnaDocumento('vencimiento', 'Vencimiento', TipoColumnaDocumento::Fecha, 13),
            new ColumnaDocumento('capital', 'Capital', TipoColumnaDocumento::Monetario, 14),
            new ColumnaDocumento('intereses', 'Intereses', TipoColumnaDocumento::Monetario, 14),
            new ColumnaDocumento('total', 'Total de la cuota', TipoColumnaDocumento::Monetario, 16),
            new ColumnaDocumento('saldo_capital', 'Saldo capital', TipoColumnaDocumento::Monetario, 15),
            new ColumnaDocumento('estado', 'Estado', TipoColumnaDocumento::Texto, 10),
        ];
    }

    public function filas(): iterable
    {
        foreach ($this->credito->cuotas as $cuota) {
            yield [
                'numero' => $cuota->numero_cuota,
                'vencimiento' => $cuota->fecha_vencimiento,
                'capital' => $cuota->valor_capital,
                'intereses' => $cuota->valor_interes,
                'total' => $cuota->valor_total_cuota,
                'saldo_capital' => $this->saldoCapitalTeoricoPorCuota[$cuota->numero_cuota] ?? Dinero::cero(),
                'estado' => ucfirst($cuota->estado->value),
            ];
        }
    }

    public function tablasAdicionales(): array
    {
        return [
            new TablaDocumento(
                'Detalle de pagos',
                [
                    new ColumnaDocumento('fecha', 'Fecha', TipoColumnaDocumento::Fecha, 13),
                    new ColumnaDocumento('concepto', 'Concepto', TipoColumnaDocumento::Texto, 22),
                    new ColumnaDocumento('valor', 'Valor', TipoColumnaDocumento::Monetario, 12),
                    new ColumnaDocumento('capital', 'Capital', TipoColumnaDocumento::Monetario, 12),
                    new ColumnaDocumento('intereses', 'Intereses', TipoColumnaDocumento::Monetario, 12),
                    new ColumnaDocumento('otros_gastos', 'Otros gastos', TipoColumnaDocumento::Monetario, 12),
                    new ColumnaDocumento('forma_pago', 'Forma de pago', TipoColumnaDocumento::Texto, 17),
                ],
                $this->pagos->map(fn ($pago) => [
                    'fecha' => $pago->fecha_pago,
                    'concepto' => $pago->reversa ? 'Reversado: '.$pago->reversa->motivo : ($pago->observacion ?: 'Pago'),
                    'valor' => $pago->valor_pago,
                    'capital' => $pago->valor_capital ?? Dinero::cero(),
                    'intereses' => $pago->valor_interes ?? Dinero::cero(),
                    'otros_gastos' => $pago->valor_otros_gastos ?? Dinero::cero(),
                    'forma_pago' => ucfirst($pago->forma_pago->value),
                ])->values()->all(),
                [
                    'concepto' => 'Total pagado',
                    'valor' => $this->totalPagado(),
                    'capital' => $this->totalDesglose('valor_capital'),
                    'intereses' => $this->totalDesglose('valor_interes'),
                    'otros_gastos' => $this->totalDesglose('valor_otros_gastos'),
                ],
                'Aún no se han registrado pagos.',
            ),
        ];
    }

    public function totales(): array
    {
        return [
            new TotalDocumento('Monto prestado', $this->credito->valor_prestamo),
            new TotalDocumento('Cuota fija', $this->credito->valor_cuota_total),
            new TotalDocumento('Total pagado', $this->totalPagado()),
            new TotalDocumento('Saldo pendiente', $this->saldo),
        ];
    }

    public function secciones(): array
    {
        $pagadas = $this->credito->cuotas->filter(fn ($cuota) => $cuota->estado === EstadoCuota::Pagada)->count();

        $secciones = [
            new SeccionDocumento(
                'Resumen del crédito',
                'Periodicidad: '.ucfirst($this->credito->tipo_pago->value)."\nCuotas pagadas / pactadas: {$pagadas} / {$this->credito->plazo}",
            ),
        ];

        if ($this->refinanciacion) {
            $secciones[] = $this->seccionRefinanciacion($this->refinanciacion);
        }

        return $secciones;
    }

    public function pie(): ?string
    {
        return "Este documento es válido para pago total únicamente al día de generado.\nCualquier inquietud, favor comunicarla por escrito a los canales de atención de la empresa.";
    }

    public function marcaAgua(): ?string
    {
        return null;
    }

    public function camposEmpresa(): CamposEmpresaDocumento
    {
        return new CamposEmpresaDocumento(direccion: false, telefono: false, ubicacion: false, correo: false);
    }

    /** Los pagos reversados siguen en el detalle para trazabilidad, pero no suman al total recaudado. */
    private function pagosVigentes(): Collection
    {
        return $this->pagos->filter(fn ($pago) => $pago->reversa === null);
    }

    private function totalPagado(): Dinero
    {
        return Dinero::sumarVarios(...$this->pagosVigentes()->map(fn ($pago) => $pago->valor_pago)->all());
    }

    private function totalDesglose(string $campo): Dinero
    {
        return Dinero::sumarVarios(...$this->pagosVigentes()->map(fn ($pago) => $pago->{$campo} ?? Dinero::cero())->all());
    }

    /** @param array<string, mixed> $vinculo */
    private function seccionRefinanciacion(array $vinculo): SeccionDocumento
    {
        $refinancia = $vinculo['tipo'] === 'refinancia';
        $originalCongelado = $vinculo['credito_relacionado']['estado']->value === 'refinanciado';

        $descripcion = $refinancia
            ? ($originalCongelado
                ? 'Este crédito refinanció al crédito original, que quedó congelado como historial. Los pagos se aplican a este crédito.'
                : 'Este crédito refinancia al crédito original, que quedará congelado como historial cuando se desembolse este crédito.')
            : 'Este crédito quedó congelado como historial. Los pagos se aplican a la refinanciación que lo reemplazó.';

        if ($refinancia && $vinculo['saldo_tomado'] !== null && $vinculo['valor_adicional'] !== null) {
            $descripcion .= "\nSaldo de capital tomado: ".$this->formateador->dinero($vinculo['saldo_tomado'])
                ."\nNuevo valor prestado: ".$this->formateador->dinero($vinculo['valor_adicional'])
                ."\nValor de este crédito: ".$this->formateador->dinero($this->credito->valor_prestamo);
        }

        return new SeccionDocumento($refinancia ? 'Refinanciación' : 'Crédito refinanciado', $descripcion);
    }
}
