<?php

namespace App\ObligacionesTributarias\Services;

use App\Auditoria\Services\EmisorAuditoria;
use App\ObligacionesTributarias\Constants\ObligacionesTributariasConstants;
use App\ObligacionesTributarias\Models\TipoObligacionTributaria;
use App\ObligacionesTributarias\Repositories\ObligacionTributariaRepository;
use App\ObligacionesTributarias\Repositories\TipoObligacionTributariaRepository;
use App\Usuarios\Models\Usuario;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TipoObligacionTributariaService
{
    public function __construct(
        protected TipoObligacionTributariaRepository $tipos,
        protected ObligacionTributariaRepository $obligaciones,
        protected EmisorAuditoria $auditoria,
    ) {}

    public function listar(Usuario $usuario, bool $soloActivos = false): Collection
    {
        return $this->tipos->deEmpresa((int) $usuario->empresa_id, $soloActivos);
    }

    public function crear(Usuario $usuario, array $datos): TipoObligacionTributaria
    {
        return DB::transaction(function () use ($usuario, $datos) {
            $tipo = $this->tipos->crear([...$datos, 'creado_por' => $usuario->id]);

            $this->auditoria->registrarCreacion(
                ObligacionesTributariasConstants::MODULO,
                "Tipo de obligación tributaria «{$tipo->nombre}» creado",
                $tipo,
            );

            return $tipo;
        });
    }

    public function editar(TipoObligacionTributaria $tipo, array $datos): TipoObligacionTributaria
    {
        return DB::transaction(function () use ($tipo, $datos) {
            $anteriores = $this->auditoria->instantanea($tipo);
            $tipo = $this->tipos->actualizar($tipo, $datos);

            $this->auditoria->registrarModificacion(
                ObligacionesTributariasConstants::MODULO,
                "Tipo de obligación tributaria «{$tipo->nombre}» modificado",
                $tipo,
                $anteriores,
            );

            return $tipo;
        });
    }

    /** Con obligaciones registradas el tipo se desactiva, no se borra: el historial del calendario lo sigue mostrando. */
    public function eliminar(TipoObligacionTributaria $tipo): void
    {
        if ($this->obligaciones->existenDeTipo($tipo->id)) {
            throw ValidationException::withMessages([
                'tipo' => __('validation.custom.obligaciones_tributarias.tipo_con_obligaciones'),
            ]);
        }

        DB::transaction(function () use ($tipo) {
            $this->tipos->eliminar($tipo);

            $this->auditoria->registrarEliminacion(
                ObligacionesTributariasConstants::MODULO,
                "Tipo de obligación tributaria «{$tipo->nombre}» eliminado",
                $tipo,
            );
        });
    }
}
