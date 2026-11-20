<?php

namespace App\Creditos\Services;

use App\Auditoria\Constants\AuditoriaConstants;
use App\Auditoria\Enums\AccionAuditoria;
use App\Auditoria\Services\EmisorAuditoria;
use App\Clientes\Models\Cliente;
use App\Clientes\Repositories\ClienteRepository;
use App\Creditos\Documentos\SimulacionCreditoPdf;
use App\Empresas\Services\EmpresaService;
use App\Impresion\Data\PdfGenerado;
use App\Impresion\Services\GeneradorPdf;

class SimulacionCreditoService
{
    public function __construct(
        protected CreditoService $creditos,
        protected ClienteRepository $clientes,
        protected EmpresaService $empresas,
        protected GeneradorPdf $generador,
        protected EmisorAuditoria $auditoria,
    ) {}

    /** Mismo cálculo que la previsualización, impreso con la plantilla de documentos: nada se persiste salvo el evento de bitácora. */
    public function generarPdf(array $datos): PdfGenerado
    {
        /** @var Cliente $cliente */
        $cliente = $this->clientes->buscarPorIdOFallar($datos['cliente_id']);
        $cliente->loadMissing('tercero');

        $documento = new SimulacionCreditoPdf($cliente, $datos, $this->creditos->previsualizarCredito($datos), now());

        $pdf = new PdfGenerado($this->generador->generar($documento, $this->empresas->obtenerPropia()), $documento->nombreArchivo());

        $this->auditoria->registrar(
            AccionAuditoria::Exportar,
            AuditoriaConstants::MODULO_CREDITOS,
            "Simulación de crédito para el cliente #{$cliente->id} descargada en PDF",
            'cliente',
            $cliente->id,
        );

        return $pdf;
    }
}
