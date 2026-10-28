<?php

namespace App\Causacion\Services;

use App\Auditoria\Constants\AuditoriaConstants;
use App\Auditoria\Services\EmisorAuditoria;
use App\Causacion\Models\ValorUvt;
use App\Causacion\Repositories\ValorUvtRepository;
use App\Core\Dinero\Dinero;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ValorUvtService
{
    public function __construct(
        protected ValorUvtRepository $valoresUvt,
        protected EmisorAuditoria $auditoria,
    ) {}

    public function listar(): Collection
    {
        return $this->valoresUvt->todas();
    }

    /**
     * Nunca sobrescribe un valor ya guardado: si hay una vigencia abierta (sin `fecha_hasta`), solo le
     * cierra la fecha (un día antes de la nueva) y crea el valor nuevo como fila aparte. Mismo patrón que
     * {@see ParametrizacionTipoGastoService::crear()}.
     */
    public function crear(string $fechaDesde, Dinero $valor): ValorUvt
    {
        return DB::transaction(function () use ($fechaDesde, $valor) {
            if ($this->valoresUvt->existeEnRangoCerrado($fechaDesde)) {
                throw ValidationException::withMessages([
                    'fecha_desde' => __('validation.custom.valor_uvt.solapa_rango_cerrado'),
                ]);
            }

            $abierto = $this->valoresUvt->abierta();

            if ($abierto) {
                $anteriores = $this->auditoria->instantanea($abierto);

                $this->valoresUvt->actualizar($abierto, [
                    'fecha_hasta' => Carbon::parse($fechaDesde)->subDay()->toDateString(),
                ]);

                $this->auditoria->registrarModificacion(
                    AuditoriaConstants::MODULO_GASTOS,
                    "Valor UVT #{$abierto->id} cerrado por una nueva vigencia",
                    $abierto,
                    $anteriores,
                );
            }

            $uvt = $this->valoresUvt->crear(['fecha_desde' => $fechaDesde, 'valor' => $valor]);

            $this->auditoria->registrarCreacion(AuditoriaConstants::MODULO_GASTOS, "Valor UVT #{$uvt->id} vigente desde {$fechaDesde}", $uvt);

            return $uvt;
        });
    }

    public function editar(ValorUvt $valorUvt, Dinero $valor): ValorUvt
    {
        $this->exigirSinUso($valorUvt);

        $anteriores = $this->auditoria->instantanea($valorUvt);
        $valorUvt = $this->valoresUvt->actualizar($valorUvt, ['valor' => $valor]);

        $this->auditoria->registrarModificacion(AuditoriaConstants::MODULO_GASTOS, "Valor UVT #{$valorUvt->id} modificado", $valorUvt, $anteriores);

        return $valorUvt;
    }

    public function eliminar(ValorUvt $valorUvt): bool
    {
        $this->exigirSinUso($valorUvt);

        $eliminado = $this->valoresUvt->eliminar($valorUvt);

        $this->auditoria->registrarEliminacion(AuditoriaConstants::MODULO_GASTOS, "Valor UVT #{$valorUvt->id} eliminado", $valorUvt);

        return $eliminado;
    }

    /** Si ya hay gastos causados en su rango de vigencia, el valor queda protegido: hay que crear una vigencia nueva. */
    private function exigirSinUso(ValorUvt $valorUvt): void
    {
        if ($this->valoresUvt->tieneUso($valorUvt)) {
            throw ValidationException::withMessages([
                'valor_uvt' => __('validation.custom.valor_uvt.tiene_gastos_asociados'),
            ]);
        }
    }
}
