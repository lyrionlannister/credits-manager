<?php

namespace App\Creditos\Controllers;

use App\Creditos\Enums\EstadoCredito;
use App\Creditos\Models\Credito;
use App\Creditos\Requests\ActualizarGastosAdministracionRequest;
use App\Creditos\Requests\CrearCreditoRequest;
use App\Creditos\Requests\EditarCreditoRequest;
use App\Creditos\Requests\ExportarCreditosRequest;
use App\Creditos\Requests\ExportarEstadoCuentaRequest;
use App\Creditos\Requests\PrevisualizarCreditoRequest;
use App\Creditos\Requests\PrevisualizarEdicionCreditoRequest;
use App\Creditos\Requests\ReprogramarCreditoRequest;
use App\Creditos\Services\CreditoService;
use App\Creditos\Services\CuotaService;
use App\Creditos\Services\EstadoCuentaService;
use App\Creditos\Services\SimulacionCreditoService;
use App\Exportacion\Enums\FormatoExportacion;
use App\Http\Controllers\Controller;
use App\Pagos\Services\PagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CreditoController extends Controller
{
    public function __construct(
        protected CreditoService $creditos,
        protected CuotaService $cuotas,
        protected PagoService $pagos,
        protected EstadoCuentaService $estadosCuenta,
        protected SimulacionCreditoService $simulaciones,
    ) {}

    public function index(Request $peticion): JsonResponse
    {
        $estado = $peticion->string('estado')->toString();

        $creditos = $this->creditos->listarCreditos(
            busqueda: $peticion->string('search')->toString(),
            porPagina: $peticion->integer('per_page', 10),
            pagina: $peticion->integer('page', 1),
            clienteId: $peticion->integer('cliente_id') ?: null,
            estado: $estado ? EstadoCredito::from($estado) : null,
        );

        return response()->json($creditos);
    }

    public function exportar(ExportarCreditosRequest $peticion): JsonResponse
    {
        $exportacion = $this->creditos->solicitarExportacion(
            $peticion->user(),
            $peticion->validated('search'),
            $peticion->validated('estado') ? EstadoCredito::from($peticion->validated('estado')) : null,
            FormatoExportacion::from($peticion->validated('formato')),
        );

        return response()->json(['exportacion' => $exportacion], 202);
    }

    public function store(CrearCreditoRequest $peticion): JsonResponse
    {
        $credito = $this->creditos->crearCredito($peticion->validated());

        return response()->json(['credito' => $credito], 201);
    }

    public function previsualizar(PrevisualizarCreditoRequest $peticion): JsonResponse
    {
        return response()->json($this->creditos->previsualizarCredito($peticion->validated()));
    }

    public function previsualizarPdf(PrevisualizarCreditoRequest $peticion): Response
    {
        $pdf = $this->simulaciones->generarPdf($peticion->validated());

        return response($pdf->contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->nombreArchivo.'.pdf"',
        ]);
    }

    public function previsualizarEdicion(PrevisualizarEdicionCreditoRequest $peticion, Credito $credito): JsonResponse
    {
        return response()->json($this->creditos->previsualizarEdicion($credito, $peticion->validated()));
    }

    public function update(EditarCreditoRequest $peticion, Credito $credito): JsonResponse
    {
        return response()->json(['credito' => $this->creditos->editarCondiciones($credito, $peticion->validated())]);
    }

    public function reprogramar(ReprogramarCreditoRequest $peticion, Credito $credito): JsonResponse
    {
        return response()->json(['credito' => $this->creditos->reprogramar($credito, $peticion->validated())]);
    }

    public function actualizarGastosAdministracion(ActualizarGastosAdministracionRequest $peticion, Credito $credito): JsonResponse
    {
        $credito = $this->creditos->actualizarGastosAdministracion(
            $credito,
            (float) $peticion->validated('gastos_administracion_pct'),
            $peticion->validated('motivo'),
        );

        return response()->json(['credito' => $credito]);
    }

    public function show(Credito $credito): JsonResponse
    {
        $credito = $this->creditos->mostrarCredito($credito);

        return response()->json([
            'credito' => $credito,
            'saldo' => $this->cuotas->saldoCredito($credito->id),
            'cartera' => $this->cuotas->estadoCartera($credito),
            'mora' => $this->cuotas->resumenMora($credito->id),
            'refinanciacion' => $this->creditos->vinculoRefinanciacion($credito),
            'pagos' => $this->pagos->listarPorCredito($credito->id, 'desc'),
            'resumen_cuotas' => $this->cuotas->resumenAlDia($credito->id, $credito->valor_prestamo),
        ]);
    }

    public function estadoCuenta(Credito $credito): JsonResponse
    {
        return response()->json($this->estadosCuenta->obtener($credito));
    }

    public function estadoCuentaExportar(Credito $credito, ExportarEstadoCuentaRequest $peticion): JsonResponse
    {
        $exportacion = $this->estadosCuenta->solicitarExportacion(
            $credito,
            $peticion->user(),
            FormatoExportacion::from($peticion->validated('formato')),
        );

        return response()->json(['exportacion' => $exportacion], 202);
    }

    public function estadoCuentaPdf(Credito $credito): Response
    {
        $pdf = $this->estadosCuenta->generarPdf($credito);

        return response($pdf->contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->nombreArchivo.'.pdf"',
        ]);
    }

    public function aprobar(Credito $credito): JsonResponse
    {
        return response()->json(['credito' => $this->creditos->aprobar($credito)]);
    }

    public function desembolsar(Credito $credito): JsonResponse
    {
        return response()->json(['credito' => $this->creditos->desembolsar($credito)]);
    }

    public function cancelar(Credito $credito): JsonResponse
    {
        return response()->json(['credito' => $this->creditos->cancelar($credito)]);
    }

    public function destroy(Credito $credito): Response
    {
        $this->creditos->eliminar($credito);

        return response()->noContent();
    }
}
