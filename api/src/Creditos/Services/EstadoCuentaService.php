<?php

namespace App\Creditos\Services;

use App\Auditoria\Constants\AuditoriaConstants;
use App\Auditoria\Enums\AccionAuditoria;
use App\Auditoria\Services\EmisorAuditoria;
use App\Core\Dinero\Dinero;
use App\Creditos\Documentos\EstadoCuentaPdf;
use App\Creditos\Exportaciones\FuenteEstadoCuenta;
use App\Creditos\Models\Credito;
use App\Empresas\Services\EmpresaService;
use App\Exportacion\Enums\FormatoExportacion;
use App\Exportacion\Models\Exportacion;
use App\Exportacion\Services\ExportacionService;
use App\Impresion\Data\PdfGenerado;
use App\Impresion\Services\GeneradorPdf;
use App\Pagos\Services\PagoService;
use App\Usuarios\Models\Usuario;
use Illuminate\Support\Collection;

class EstadoCuentaService
{
    public function __construct(
        protected CreditoService $creditos,
        protected CuotaService $cuotas,
        protected PagoService $pagos,
        protected EmpresaService $empresas,
        protected GeneradorPdf $generador,
        protected EmisorAuditoria $auditoria,
        protected ExportacionService $exportaciones,
    ) {}

    public function solicitarExportacion(Credito $credito, Usuario $usuario, FormatoExportacion $formato): Exportacion
    {
        $this->cuotas->actualizarVencidas();

        return $this->exportaciones->solicitar(new FuenteEstadoCuenta((int) $usuario->empresa_id, $credito->id), $formato, $usuario);
    }

    /** @return array{credito: Credito, fecha_corte: string, saldo: Dinero, refinanciacion: array<string, mixed>|null, pagos: Collection, resumen_cuotas: array<string, mixed>} */
    public function obtener(Credito $credito): array
    {
        $credito = $this->creditos->mostrarCredito($credito);

        // La fecha real del pago de cada cuota viaja como atributo de la cuota: la pantalla y el PDF la muestran junto al vencimiento.
        $fechasPago = $this->pagos->fechasDePagoPorCuota($credito->id, $credito->cuotas);
        $credito->cuotas->each(fn ($cuota) => $cuota->setAttribute('fecha_pago', $fechasPago[$cuota->id] ?? null));

        // La fecha de corte es el día en que se genera: el estado de cuenta refleja los movimientos hasta ese momento.
        return [
            'credito' => $credito,
            'fecha_corte' => now('America/Bogota')->toDateString(),
            'saldo' => $this->cuotas->saldoCredito($credito->id),
            'refinanciacion' => $this->creditos->vinculoRefinanciacion($credito),
            'pagos' => $this->pagos->listarPorCredito($credito->id, 'asc'),
            'resumen_cuotas' => $this->cuotas->resumenAlDia($credito->id, $credito->valor_prestamo),
        ];
    }

    public function generarPdf(Credito $credito): PdfGenerado
    {
        $estado = $this->obtener($credito);

        $documento = new EstadoCuentaPdf(
            $estado['credito'],
            $estado['saldo'],
            $estado['refinanciacion'],
            $estado['pagos'],
            $estado['resumen_cuotas']['saldo_capital_teorico_por_cuota'],
            now(),
        );

        $pdf = new PdfGenerado($this->generador->generar($documento, $this->empresas->obtenerPropia()), $documento->nombreArchivo());

        $this->auditoria->registrar(
            AccionAuditoria::Exportar,
            AuditoriaConstants::MODULO_CREDITOS,
            "Estado de cuenta del crédito #{$credito->id} descargado en PDF",
            'credito',
            $credito->id,
        );

        return $pdf;
    }
}
