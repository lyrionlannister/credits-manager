<?php

namespace App\Importacion\Procesadores;

use App\Causacion\Services\ValorUvtService;
use App\Importacion\Data\ContextoEjecucion;
use App\Importacion\Data\FilaRechazada;
use App\Importacion\Lectura\ConvertidorValor;
use Illuminate\Validation\ValidationException;

/** Abre una vigencia nueva; el Service cierra la vigencia abierta anterior, si existía. */
final class ProcesadorValoresUvt implements ProcesadorFila
{
    public function __construct(private readonly ValorUvtService $valoresUvt) {}

    public function precargar(array $valoresLote, ContextoEjecucion $ctx): void {}

    public function procesar(array $valores, ContextoEjecucion $ctx): void
    {
        $fechaDesde = ConvertidorValor::fecha($valores['fecha_desde'], 'fecha_desde')
            ?? throw new FilaRechazada('La fecha desde es obligatoria.', 'fecha_desde');

        $valor = ConvertidorValor::monto($valores['valor'], 'valor')
            ?? throw new FilaRechazada('El valor UVT es obligatorio.', 'valor');

        if (! $valor->esPositivo()) {
            throw new FilaRechazada('El valor UVT debe ser mayor que cero.', 'valor');
        }

        if (! $ctx->escribir) {
            return;
        }

        try {
            $this->valoresUvt->crear($fechaDesde, $valor);
        } catch (ValidationException $excepcion) {
            throw new FilaRechazada($excepcion->validator->errors()->first(), 'fecha_desde');
        }
    }
}
