<?php

namespace App\Cartera\Controllers;

use App\Cartera\Requests\DescargarReporteEmpleadorRequest;
use App\Cartera\Requests\ExportarCarteraRequest;
use App\Cartera\Requests\ExportarReporteEmpleadorRequest;
use App\Cartera\Services\CarteraService;
use App\Cartera\Services\ReporteEmpleadorService;
use App\Exportacion\Enums\FormatoExportacion;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CarteraController extends Controller
{
    public function __construct(
        protected CarteraService $cartera,
        protected ReporteEmpleadorService $reportesEmpleador,
    ) {}

    public function index(Request $peticion): JsonResponse
    {
        return response()->json($this->cartera->resumen($peticion->user()));
    }

    public function exportar(ExportarCarteraRequest $peticion): JsonResponse
    {
        $exportacion = $this->cartera->solicitarExportacion(
            $peticion->user(),
            FormatoExportacion::from($peticion->validated('formato')),
        );

        return response()->json(['exportacion' => $exportacion], 202);
    }

    public function reporteEmpleador(Request $peticion): JsonResponse
    {
        return response()->json($this->cartera->reporteEmpleador($peticion->integer('empleador_id') ?: null));
    }

    public function reporteEmpleadorPdf(DescargarReporteEmpleadorRequest $peticion): Response
    {
        $pdf = $this->reportesEmpleador->generarPdf($peticion->integer('empleador_id'));

        return response($pdf->contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->nombreArchivo.'.pdf"',
        ]);
    }

    public function exportarReporteEmpleador(ExportarReporteEmpleadorRequest $peticion): JsonResponse
    {
        $exportacion = $this->reportesEmpleador->solicitarExportacion(
            $peticion->user(),
            $peticion->integer('empleador_id'),
            FormatoExportacion::from($peticion->validated('formato')),
        );

        return response()->json(['exportacion' => $exportacion], 202);
    }
}
