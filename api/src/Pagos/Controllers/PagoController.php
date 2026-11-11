<?php

namespace App\Pagos\Controllers;

use App\Core\Dinero\Dinero;
use App\Creditos\Models\Credito;
use App\Exportacion\Enums\FormatoExportacion;
use App\Http\Controllers\Controller;
use App\Pagos\Enums\FormaPago;
use App\Pagos\Models\Pago;
use App\Pagos\Requests\CotizarLiquidacionRequest;
use App\Pagos\Requests\CrearPagoRequest;
use App\Pagos\Requests\ExportarPagosRequest;
use App\Pagos\Requests\LiquidarCreditoRequest;
use App\Pagos\Requests\ListarPagosRequest;
use App\Pagos\Requests\ReversarPagoRequest;
use App\Pagos\Services\PagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class PagoController extends Controller
{
    public function __construct(protected PagoService $pagoService) {}

    public function listar(ListarPagosRequest $peticion): JsonResponse
    {
        $datos = $peticion->validated();

        $pagos = $this->pagoService->listar(
            busqueda: $datos['search'] ?? null,
            mes: $datos['mes'] ?? null,
            porPagina: (int) ($datos['per_page'] ?? 10),
            pagina: (int) ($datos['page'] ?? 1),
        );

        return response()->json($pagos);
    }

    public function exportar(ExportarPagosRequest $peticion): JsonResponse
    {
        $exportacion = $this->pagoService->solicitarExportacion(
            $peticion->user(),
            $peticion->validated('search'),
            $peticion->validated('mes'),
            FormatoExportacion::from($peticion->validated('formato')),
        );

        return response()->json(['exportacion' => $exportacion], 202);
    }

    public function index(Credito $credito): JsonResponse
    {
        return response()->json(['pagos' => $this->pagoService->listarPorCredito($credito->id, 'desc')]);
    }

    public function store(CrearPagoRequest $peticion, Credito $credito): JsonResponse
    {
        $datos = $peticion->validated();

        $resultado = $this->pagoService->registrarPago(
            $credito,
            Dinero::desdePesos($datos['valor_pago']),
            FormaPago::from($datos['forma_pago']),
            $datos['observacion'] ?? null,
            Auth::id(),
            $datos['fecha_pago'] ?? null,
        );

        return response()->json([
            'pago' => $resultado['pago'],
            'sobrante' => $resultado['sobrante'],
        ], 201);
    }

    public function reversar(ReversarPagoRequest $peticion, Pago $pago): JsonResponse
    {
        $reversa = $this->pagoService->reversar($pago, $peticion->validated('motivo'), Auth::id());

        return response()->json(['reversa' => $reversa]);
    }

    public function liquidacion(CotizarLiquidacionRequest $peticion, Credito $credito): JsonResponse
    {
        return response()->json($this->pagoService->calcularLiquidacion($credito, $peticion->validated('fecha_pago')));
    }

    public function liquidar(LiquidarCreditoRequest $peticion, Credito $credito): JsonResponse
    {
        $datos = $peticion->validated();

        $resultado = $this->pagoService->liquidarCredito(
            $credito,
            FormaPago::from($datos['forma_pago']),
            $datos['observacion'] ?? null,
            Auth::id(),
            $datos['fecha_pago'] ?? null,
        );

        return response()->json($resultado, 201);
    }
}
