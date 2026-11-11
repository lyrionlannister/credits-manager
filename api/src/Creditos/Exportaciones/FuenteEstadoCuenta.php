<?php

namespace App\Creditos\Exportaciones;

use App\Core\Dinero\Dinero;
use App\Creditos\Repositories\CreditoRepository;
use App\Creditos\Repositories\CuotaRepository;
use App\Exportacion\Contracts\FuenteExportable;
use App\Exportacion\Data\ColumnaExportable;
use App\Exportacion\Enums\TipoColumna;
use App\Exportacion\Services\AccesoExportaciones;
use App\Pagos\Services\PagoService;
use App\Usuarios\Models\Usuario;
use Carbon\CarbonImmutable;

/**
 * Plan de cuotas de un crédito con lo pagado y el saldo, a Excel/CSV. Las mismas columnas del estado de cuenta
 * en PDF. Serializable: empresa y crédito; el crédito se vuelve a validar contra la empresa en el worker.
 */
final class FuenteEstadoCuenta implements FuenteExportable
{
    public function __construct(
        private readonly int $empresaId,
        private readonly int $creditoId,
    ) {}

    public function reporte(): string
    {
        return 'creditos.estado_cuenta';
    }

    public function modulo(): string
    {
        return 'creditos';
    }

    public function titulo(): string
    {
        return 'Estado de cuenta del crédito';
    }

    public function nombreArchivo(): string
    {
        return "estado-de-cuenta-credito-{$this->creditoId}";
    }

    public function columnas(): array
    {
        return [
            new ColumnaExportable('numero', 'Cuota', TipoColumna::Entero),
            new ColumnaExportable('vencimiento', 'Vencimiento', TipoColumna::Fecha),
            new ColumnaExportable('fecha_pago', 'Fecha de pago', TipoColumna::Fecha),
            new ColumnaExportable('capital', 'Capital', TipoColumna::Monetario),
            new ColumnaExportable('intereses', 'Intereses', TipoColumna::Monetario),
            new ColumnaExportable('total', 'Total de la cuota', TipoColumna::Monetario),
            new ColumnaExportable('pagado', 'Pagado', TipoColumna::Monetario),
            new ColumnaExportable('saldo_capital', 'Saldo de capital', TipoColumna::Monetario),
            new ColumnaExportable('estado', 'Estado'),
        ];
    }

    public function filas(): iterable
    {
        app(CreditoRepository::class)->buscarDeEmpresaOFallar($this->empresaId, $this->creditoId);

        $total = Dinero::cero();
        $totalPagado = Dinero::cero();

        // El crédito ya se validó contra la empresa: las fechas de pago se leen por su id, también sin sesión en el worker.
        $cuotas = app(CuotaRepository::class)->todasPorCredito($this->creditoId);
        $fechasPago = app(PagoService::class)->fechasDePagoPorCuota($this->creditoId, $cuotas);

        foreach ($cuotas as $cuota) {
            $total = $total->sumar($cuota->valor_total_cuota);
            $totalPagado = $totalPagado->sumar($cuota->valor_pagado);

            yield [
                'numero' => $cuota->numero_cuota,
                'vencimiento' => $cuota->fecha_vencimiento,
                'fecha_pago' => isset($fechasPago[$cuota->id]) ? CarbonImmutable::parse($fechasPago[$cuota->id]) : null,
                'capital' => $cuota->valor_capital,
                'intereses' => $cuota->valor_interes,
                'total' => $cuota->valor_total_cuota,
                'pagado' => $cuota->valor_pagado,
                'saldo_capital' => $cuota->saldo_capital,
                'estado' => $cuota->estado->value,
            ];
        }

        yield [
            'numero' => null,
            'vencimiento' => null,
            'fecha_pago' => null,
            'capital' => null,
            'intereses' => null,
            'total' => $total,
            'pagado' => $totalPagado,
            'saldo_capital' => null,
            'estado' => 'Total',
        ];
    }

    public function totalFilas(): int
    {
        app(CreditoRepository::class)->buscarDeEmpresaOFallar($this->empresaId, $this->creditoId);

        return app(CuotaRepository::class)->cursorDelCredito($this->creditoId)->count() + 1;
    }

    public function filtrosAplicados(): array
    {
        return ['credito_id' => $this->creditoId];
    }

    public function autorizadoPara(Usuario $usuario): bool
    {
        return $usuario->empresa_id !== null && app(AccesoExportaciones::class)->puede($usuario, $this->reporte());
    }
}
