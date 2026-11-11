<?php

namespace App\Exportacion\Middleware;

use App\Exportacion\Services\AccesoExportaciones;
use App\Soporte\Services\AccesoSoporteService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `exportacion:{reporte},...`: la ruta nombra la clave del reporte, no el permiso, y el permiso sale del mismo mapa que
 * usan la fuente y la descarga. Con varias claves basta una (el libro mayor se exporta como mayor o como caja y bancos).
 */
class PuedeExportar
{
    public function __construct(protected AccesoExportaciones $acceso) {}

    public function handle(Request $peticion, Closure $siguiente, string ...$reportes): Response
    {
        $usuario = $peticion->user();

        // Fase 4: el acceso de soporte no exporta, aunque la lectura esté permitida.
        if ($usuario !== null && $usuario->es_superadmin
            && app(AccesoSoporteService::class)->accesoActivo($usuario) !== null) {
            return response()->json([
                'message' => 'El acceso de soporte no permite exportar.',
                'motivo_denegacion' => 'acceso_soporte_sin_exportar',
            ], 403);
        }

        $autorizado = $usuario !== null && collect($reportes)->contains(fn (string $reporte) => $this->acceso->puede($usuario, $reporte));

        if (! $autorizado) {
            return response()->json([
                'message' => 'No tienes permiso para realizar esta acción.',
                'motivo_denegacion' => 'sin_permiso_exportar',
            ], 403);
        }

        return $siguiente($peticion);
    }
}
