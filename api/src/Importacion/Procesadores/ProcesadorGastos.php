<?php

namespace App\Importacion\Procesadores;

use App\Causacion\Models\TipoGasto;
use App\Causacion\Repositories\ParametrizacionTipoGastoRepository;
use App\Causacion\Repositories\ValorUvtRepository;
use App\Contabilidad\Models\CentroCosto;
use App\Gastos\Services\GastoService;
use App\Geografia\Models\Municipio;
use App\Importacion\Data\ContextoEjecucion;
use App\Importacion\Data\FilaRechazada;
use App\Importacion\Lectura\ConvertidorValor;
use App\Terceros\Models\Tercero;
use Illuminate\Validation\ValidationException;

/**
 * Gastos históricos. Cada fila se causa con el mismo servicio que el registro manual, así que la parametrización, las
 * retenciones y el asiento son los de un gasto capturado a mano. Sin escribir, solo se comprueba lo que el servicio
 * exige antes de causar; las tarifas de ReteICA se validan al escribir.
 */
final class ProcesadorGastos implements ProcesadorFila
{
    public function __construct(
        private readonly GastoService $gastos,
        private readonly ParametrizacionTipoGastoRepository $parametrizaciones,
        private readonly ValorUvtRepository $valoresUvt,
    ) {}

    public function precargar(array $valoresLote, ContextoEjecucion $ctx): void {}

    public function procesar(array $valores, ContextoEjecucion $ctx): void
    {
        $fecha = ConvertidorValor::fecha($valores['fecha'], 'fecha')
            ?? throw new FilaRechazada('La fecha del gasto es obligatoria.', 'fecha');

        if ($valores['numero_documento'] === '') {
            throw new FilaRechazada('El número de documento es obligatorio.', 'numero_documento');
        }

        if ($valores['concepto'] === '') {
            throw new FilaRechazada('El concepto es obligatorio.', 'concepto');
        }

        $valor = ConvertidorValor::monto($valores['valor_bruto'], 'valor_bruto')
            ?? throw new FilaRechazada('El valor bruto es obligatorio.', 'valor_bruto');

        if (! $valor->esPositivo()) {
            throw new FilaRechazada('El valor bruto debe ser mayor que cero.', 'valor_bruto');
        }

        $tipo = $this->tipoGasto($ctx->empresaId, $valores['tipo_gasto']);
        $centro = $this->centroCosto($ctx->empresaId, $valores['centro_costo']);
        $tercero = $valores['tercero'] !== '' ? $this->tercero($ctx->empresaId, $valores['tercero']) : null;
        $municipio = $valores['municipio'] !== '' ? $this->municipio($valores['municipio']) : null;

        if ($tipo->requiere_tercero && ! $tercero) {
            throw new FilaRechazada("El tipo «{$tipo->nombre}» necesita el documento del tercero.", 'tercero');
        }

        if (! $ctx->escribir) {
            $this->comprobarParametrizacion($tipo->id, $fecha);

            return;
        }

        try {
            $this->gastos->causar([
                'tipo_gasto_id' => $tipo->id,
                'tercero_id' => $tercero?->id,
                'municipio_id' => $municipio?->id,
                'centro_costo_id' => $centro->id,
                'fecha' => $fecha,
                'numero_documento' => $valores['numero_documento'],
                'concepto' => $valores['concepto'],
                'valor_bruto' => $valor->aDecimalString(),
            ]);
        } catch (ValidationException $excepcion) {
            $columna = array_key_first($excepcion->errors());

            throw new FilaRechazada(collect($excepcion->errors())->flatten()->first() ?? 'No se pudo causar el gasto.', $columna);
        }
    }

    private function tipoGasto(int $empresaId, string $valor): TipoGasto
    {
        if ($valor === '') {
            throw new FilaRechazada('El tipo de gasto es obligatorio.', 'tipo_gasto');
        }

        return TipoGasto::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->where(fn ($consulta) => $consulta->where('codigo', $valor)->orWhere('nombre', $valor))
            ->first()
            ?? throw new FilaRechazada("Tipo de gasto no encontrado o inactivo: «{$valor}».", 'tipo_gasto');
    }

    private function centroCosto(int $empresaId, string $valor): CentroCosto
    {
        if ($valor === '') {
            throw new FilaRechazada('El centro de costo es obligatorio.', 'centro_costo');
        }

        return CentroCosto::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->where('codigo', $valor)
            ->first()
            ?? throw new FilaRechazada("Centro de costo no encontrado o inactivo: «{$valor}».", 'centro_costo');
    }

    private function tercero(int $empresaId, string $documento): Tercero
    {
        return Tercero::query()
            ->where('empresa_id', $empresaId)
            ->where('numero_documento', $documento)
            ->first()
            ?? throw new FilaRechazada("Tercero no encontrado: documento «{$documento}».", 'tercero');
    }

    private function municipio(string $codigo): Municipio
    {
        return Municipio::query()
            ->where('activo', true)
            ->where('codigo', $codigo)
            ->first()
            ?? throw new FilaRechazada("Municipio no encontrado: código DANE «{$codigo}».", 'municipio');
    }

    private function comprobarParametrizacion(int $tipoId, string $fecha): void
    {
        if (! $this->parametrizaciones->vigenteParaFecha($tipoId, $fecha)) {
            throw new FilaRechazada('El tipo de gasto no tiene parametrización vigente en esa fecha.', 'fecha');
        }

        if (! $this->valoresUvt->vigenteParaFecha($fecha)) {
            throw new FilaRechazada('No hay valor UVT vigente para la fecha.', 'fecha');
        }
    }
}
