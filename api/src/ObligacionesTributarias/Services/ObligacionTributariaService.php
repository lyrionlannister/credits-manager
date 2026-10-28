<?php

namespace App\ObligacionesTributarias\Services;

use App\Auditoria\Services\EmisorAuditoria;
use App\ObligacionesTributarias\Constants\ObligacionesTributariasConstants;
use App\ObligacionesTributarias\Enums\EstadoObligacionTributaria;
use App\ObligacionesTributarias\Models\ObligacionTributaria;
use App\ObligacionesTributarias\Repositories\AlertaObligacionTributariaRepository;
use App\ObligacionesTributarias\Repositories\ObligacionTributariaRepository;
use App\Usuarios\Constants\UsuarioConstants;
use App\Usuarios\Models\Usuario;
use App\Usuarios\Repositories\UsuarioRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ObligacionTributariaService
{
    /** Quiénes administran el calendario tributario: no los `asesor`, que `UsuarioRepository::asignables()` sí incluye por defecto. */
    private const ROLES_RESPONSABLES = [UsuarioConstants::ROL_ADMIN, 'contable'];

    public function __construct(
        protected ObligacionTributariaRepository $obligaciones,
        protected AlertaObligacionTributariaRepository $alertas,
        protected UsuarioRepository $usuarios,
        protected EmisorAuditoria $auditoria,
    ) {}

    /** @param array{periodo?: string, mes?: string, tipo_obligacion_id?: int, estado?: string, responsable_id?: int} $filtros */
    public function listar(Usuario $usuario, array $filtros, CarbonImmutable $hoy): array
    {
        if ($mes = $filtros['mes'] ?? null) {
            $inicioMes = CarbonImmutable::createFromFormat('Y-m', $mes)->startOfMonth();
            $filtros['fecha_desde'] = $inicioMes->toDateString();
            $filtros['fecha_hasta'] = $inicioMes->endOfMonth()->toDateString();
        }

        return $this->obligaciones->deEmpresa((int) $usuario->empresa_id, $filtros)
            ->map(fn (ObligacionTributaria $obligacion) => $this->representar($obligacion, $hoy))
            ->values()
            ->all();
    }

    public function responsablesAsignables(): Collection
    {
        return $this->usuarios->asignables(self::ROLES_RESPONSABLES);
    }

    public function crear(Usuario $usuario, array $datos): array
    {
        return DB::transaction(function () use ($usuario, $datos) {
            $obligacion = $this->obligaciones->crear([
                ...$datos,
                'estado' => EstadoObligacionTributaria::Pendiente->value,
                'creado_por' => $usuario->id,
            ]);

            $this->auditoria->registrarCreacion(
                ObligacionesTributariasConstants::MODULO,
                "Obligación tributaria del período {$obligacion->periodo} creada",
                $obligacion,
            );

            return $this->representar($obligacion->load(['tipoObligacion', 'responsable']), CarbonImmutable::now());
        });
    }

    public function editar(ObligacionTributaria $obligacion, array $datos): array
    {
        return DB::transaction(function () use ($obligacion, $datos) {
            $anteriores = $this->auditoria->instantanea($obligacion);
            $obligacion = $this->obligaciones->actualizar($obligacion, $datos);

            $this->auditoria->registrarModificacion(
                ObligacionesTributariasConstants::MODULO,
                "Obligación tributaria del período {$obligacion->periodo} modificada",
                $obligacion,
                $anteriores,
            );

            return $this->representar($obligacion->load(['tipoObligacion', 'responsable']), CarbonImmutable::now());
        });
    }

    public function cambiarEstado(Usuario $usuario, ObligacionTributaria $obligacion, EstadoObligacionTributaria $estado): array
    {
        return DB::transaction(function () use ($usuario, $obligacion, $estado) {
            $anteriores = $this->auditoria->instantanea($obligacion);

            $datos = ['estado' => $estado->value];

            if ($estado === EstadoObligacionTributaria::Cumplida) {
                $datos['cumplida_en'] = now();
                $datos['cumplida_por'] = $usuario->id;
            } else {
                $datos['cumplida_en'] = null;
                $datos['cumplida_por'] = null;
            }

            $obligacion = $this->obligaciones->actualizar($obligacion, $datos);

            $this->auditoria->registrarModificacion(
                ObligacionesTributariasConstants::MODULO,
                "Obligación tributaria del período {$obligacion->periodo} marcada como {$estado->value}",
                $obligacion,
                $anteriores,
            );

            return $this->representar($obligacion->load(['tipoObligacion', 'responsable']), CarbonImmutable::now());
        });
    }

    /** Una obligación cumplida es evidencia del control tributario: se conserva. */
    public function eliminar(ObligacionTributaria $obligacion): void
    {
        if ($obligacion->estado === EstadoObligacionTributaria::Cumplida) {
            throw ValidationException::withMessages([
                'obligacion' => __('validation.custom.obligaciones_tributarias.cumplida_no_eliminable'),
            ]);
        }

        DB::transaction(function () use ($obligacion) {
            $this->alertas->eliminarDeObligacion($obligacion->id);
            $this->obligaciones->eliminar($obligacion);

            $this->auditoria->registrarEliminacion(
                ObligacionesTributariasConstants::MODULO,
                "Obligación tributaria del período {$obligacion->periodo} eliminada",
                $obligacion,
            );
        });
    }

    /** Fechas siempre como cadena plana (`Y-m-d`), nunca el datetime ISO que da el cast por defecto. */
    private function representar(ObligacionTributaria $obligacion, CarbonImmutable $hoy): array
    {
        $vencida = $this->estaVencida($obligacion, $hoy);

        return [
            'id' => $obligacion->id,
            'tipo_obligacion' => $obligacion->tipoObligacion->only(['id', 'nombre', 'periodicidad']),
            'periodo' => $obligacion->periodo,
            'fecha_limite' => $obligacion->fecha_limite->toDateString(),
            'responsable' => $obligacion->responsable?->only(['id', 'nombre']),
            'estado' => $obligacion->estado->value,
            'observaciones' => $obligacion->observaciones,
            'cumplida_en' => $obligacion->cumplida_en?->toIso8601String(),
            'vencida' => $vencida,
            'color' => $this->colorPara($obligacion, $vencida),
        ];
    }

    private function estaVencida(ObligacionTributaria $obligacion, CarbonImmutable $hoy): bool
    {
        return $obligacion->estado === EstadoObligacionTributaria::Pendiente && $obligacion->fecha_limite->lt($hoy->startOfDay());
    }

    /** Mismo criterio que `App\Calendario\Services\CalendarioService::colorPara`: la regla vive en el backend, no en el cliente. */
    private function colorPara(ObligacionTributaria $obligacion, bool $vencida): string
    {
        return match (true) {
            $obligacion->estado === EstadoObligacionTributaria::Cumplida => 'success',
            $obligacion->estado === EstadoObligacionTributaria::NoAplica => 'primary',
            $vencida => 'danger',
            default => 'warning',
        };
    }
}
