<?php

namespace App\Creditos\Documentos;

use App\Clientes\Models\Cliente;
use App\Core\Dinero\Dinero;
use App\Creditos\Enums\TipoPago;
use App\Impresion\Contracts\DocumentoPdf;
use App\Impresion\Data\CamposEmpresaDocumento;
use App\Impresion\Data\ColumnaDocumento;
use App\Impresion\Data\DestinatarioDocumento;
use App\Impresion\Data\FechaDocumento;
use App\Impresion\Data\SeccionDocumento;
use App\Impresion\Data\TotalDocumento;
use App\Impresion\Enums\OrientacionDocumento;
use App\Impresion\Enums\TipoColumnaDocumento;
use App\Impresion\Services\FormateadorDocumento;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Simulación de un crédito antes de crearlo: el plan que calculó `CreditoService::previsualizarCredito`, sin nada guardado. */
final class SimulacionCreditoPdf implements DocumentoPdf
{
    /**
     * @param  array<string, mixed>  $datos  lo validado por `PrevisualizarCreditoRequest`
     * @param  array<string, mixed>  $simulacion  resultado de `CreditoService::previsualizarCredito`
     */
    public function __construct(
        private readonly Cliente $cliente,
        private readonly array $datos,
        private readonly array $simulacion,
        private readonly CarbonInterface $generadoEn,
        private readonly FormateadorDocumento $formateador = new FormateadorDocumento,
    ) {}

    public function titulo(): string
    {
        return 'Simulación de crédito';
    }

    public function consecutivo(): ?string
    {
        return null;
    }

    public function nombreArchivo(): string
    {
        return 'simulacion-credito-'.$this->cliente->tercero->numero_documento.'-'.$this->generadoEn->format('Y-m-d');
    }

    public function orientacion(): OrientacionDocumento
    {
        return OrientacionDocumento::Vertical;
    }

    public function destinatario(): DestinatarioDocumento
    {
        return DestinatarioDocumento::desdeTercero($this->cliente->tercero);
    }

    public function fechas(): array
    {
        return [
            new FechaDocumento('Fecha de simulación', $this->generadoEn),
            new FechaDocumento('Desembolso', CarbonImmutable::parse($this->datos['fecha_desembolso'])),
        ];
    }

    public function tituloTabla(): ?string
    {
        return 'Plan de cuotas';
    }

    public function columnas(): array
    {
        return [
            new ColumnaDocumento('numero', '#', TipoColumnaDocumento::Entero, 5),
            new ColumnaDocumento('vencimiento', 'Vencimiento', TipoColumnaDocumento::Fecha, 13),
            new ColumnaDocumento('capital', 'Capital', TipoColumnaDocumento::Monetario, 14),
            new ColumnaDocumento('intereses', 'Intereses', TipoColumnaDocumento::Monetario, 14),
            new ColumnaDocumento('otros_gastos', 'Otros gastos', TipoColumnaDocumento::Monetario, 14),
            new ColumnaDocumento('total', 'Total de la cuota', TipoColumnaDocumento::Monetario, 20),
            new ColumnaDocumento('saldo_capital', 'Saldo capital', TipoColumnaDocumento::Monetario, 20),
        ];
    }

    public function filas(): iterable
    {
        foreach ($this->simulacion['cuotas'] as $cuota) {
            yield [
                'numero' => $cuota['numero_cuota'],
                'vencimiento' => CarbonImmutable::parse($cuota['fecha_vencimiento']),
                'capital' => $cuota['valor_capital'],
                'intereses' => $cuota['valor_interes'],
                'otros_gastos' => $cuota['valor_otros_gastos'],
                'total' => $cuota['valor_total_cuota'],
                'saldo_capital' => $cuota['saldo_capital'],
            ];
        }
    }

    public function tablasAdicionales(): array
    {
        return [];
    }

    public function totales(): array
    {
        $cuotas = $this->simulacion['cuotas'];

        return [
            new TotalDocumento('Monto prestado', $this->simulacion['valor_prestamo']),
            new TotalDocumento('Cuota fija', $this->simulacion['valor_cuota']->sumar($cuotas[0]['valor_otros_gastos'])),
            new TotalDocumento('Total intereses', $this->simulacion['interes_total']),
            new TotalDocumento('Total otros gastos', Dinero::sumarVarios(...array_column($cuotas, 'valor_otros_gastos'))),
            new TotalDocumento('Total a pagar', $this->simulacion['valor_total_con_otros']),
        ];
    }

    public function secciones(): array
    {
        $tipoPago = TipoPago::from($this->datos['tipo_pago']);
        $tasa = isset($this->datos['tasa_efectiva_anual'])
            ? $this->datos['tasa_efectiva_anual'].' % EA ('.$this->simulacion['tasa_interes'].' % mensual)'
            : $this->simulacion['tasa_interes'].' % mensual';
        $condiciones = 'Tasa de interés: '.$tasa
            ."\nPlazo: ".count($this->simulacion['cuotas']).' cuotas ('.$tipoPago->value.')'
            ."\nGastos de administración: ".($this->datos['gastos_administracion_pct'] ?? 0).' % mensual';

        if ($this->simulacion['saldo_refinanciado'] !== null) {
            $condiciones .= "\nRefinancia un saldo de capital de ".$this->formateador->dinero($this->simulacion['saldo_refinanciado']);
        }

        return [new SeccionDocumento('Condiciones', $condiciones)];
    }

    public function pie(): ?string
    {
        return "Simulación informativa: no constituye aprobación ni desembolso del crédito.\nLos valores pueden cambiar según la fecha real de desembolso.";
    }

    public function marcaAgua(): ?string
    {
        return 'SIMULACIÓN';
    }

    public function camposEmpresa(): CamposEmpresaDocumento
    {
        return new CamposEmpresaDocumento(direccion: false, ubicacion: false);
    }
}
