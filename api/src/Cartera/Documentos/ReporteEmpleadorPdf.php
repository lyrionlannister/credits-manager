<?php

namespace App\Cartera\Documentos;

use App\Cartera\Data\ReporteEmpleador;
use App\Impresion\Contracts\DocumentoPdf;
use App\Impresion\Data\CamposEmpresaDocumento;
use App\Impresion\Data\ColumnaDocumento;
use App\Impresion\Data\DestinatarioDocumento;
use App\Impresion\Data\FechaDocumento;
use App\Impresion\Data\TablaDocumento;
use App\Impresion\Enums\OrientacionDocumento;
use App\Impresion\Enums\TipoColumnaDocumento;
use App\Impresion\Services\FormateadorDocumento;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/** Relación de descuento por empleador: el único documento horizontal, con una columna por mes de cuotas. */
final class ReporteEmpleadorPdf implements DocumentoPdf
{
    private const ANCHO_EMPLEADO = 20;

    private const ANCHO_CUOTA = 10;

    public function __construct(
        private readonly ReporteEmpleador $reporte,
        private readonly CarbonInterface $generadoEn,
        private readonly FormateadorDocumento $formateador = new FormateadorDocumento,
    ) {}

    public function titulo(): string
    {
        return 'Relación de descuento';
    }

    public function consecutivo(): ?string
    {
        return null;
    }

    public function nombreArchivo(): string
    {
        return 'relacion-descuento-'.Str::slug($this->reporte->empleadorNombre);
    }

    public function orientacion(): OrientacionDocumento
    {
        return OrientacionDocumento::Horizontal;
    }

    public function destinatario(): DestinatarioDocumento
    {
        return new DestinatarioDocumento(
            nombre: $this->reporte->empleadorNombre,
            documento: $this->reporte->empleadorNit,
            telefono: $this->reporte->empleadorTelefono,
            direccion: $this->reporte->empleadorDireccion,
        );
    }

    public function fechas(): array
    {
        return [new FechaDocumento('Generación', $this->generadoEn, true)];
    }

    public function tituloTabla(): ?string
    {
        return null;
    }

    public function columnas(): array
    {
        $meses = $this->reporte->meses;
        $anchoMes = $meses === [] ? null : max(1, intdiv(100 - self::ANCHO_EMPLEADO - self::ANCHO_CUOTA, count($meses)));

        return [
            new ColumnaDocumento('empleado', 'Empleado', TipoColumnaDocumento::Texto, self::ANCHO_EMPLEADO),
            new ColumnaDocumento('cuota', 'Cuota', TipoColumnaDocumento::Monetario, self::ANCHO_CUOTA),
            ...array_map(
                fn (string $periodo) => new ColumnaDocumento("mes_{$periodo}", $this->reporte->etiquetaMes($periodo), TipoColumnaDocumento::Texto, $anchoMes),
                $meses,
            ),
        ];
    }

    public function filas(): iterable
    {
        foreach ($this->reporte->filas as $fila) {
            $celdas = ['empleado' => $fila['cliente_nombre'], 'cuota' => $fila['valor_cuota']];

            foreach ($this->reporte->meses as $periodo) {
                $cuota = $fila['cuotas'][$periodo] ?? null;

                // Valor y estado en dos líneas de la misma celda: así caben muchos meses en la hoja.
                $celdas["mes_{$periodo}"] = $cuota ? $this->formateador->dinero($cuota['valor'])."\n".ucfirst($cuota['estado']) : '—';
            }

            yield $celdas;
        }
    }

    public function tablasAdicionales(): array
    {
        if ($this->reporte->filas === []) {
            return [];
        }

        $total = ['empleado' => 'Total por mes'];

        foreach ($this->reporte->meses as $periodo) {
            $total["mes_{$periodo}"] = $this->formateador->dinero($this->reporte->totalesPorMes[$periodo]);
        }

        return [new TablaDocumento('', $this->columnas(), [], $total, null, false)];
    }

    public function totales(): array
    {
        return [];
    }

    public function secciones(): array
    {
        return [];
    }

    public function pie(): ?string
    {
        return null;
    }

    public function marcaAgua(): ?string
    {
        return null;
    }

    public function camposEmpresa(): CamposEmpresaDocumento
    {
        return CamposEmpresaDocumento::todos();
    }
}
