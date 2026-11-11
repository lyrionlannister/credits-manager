<?php

namespace App\Core\Scopes;

use App\Soporte\Services\AccesoSoporteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Filtra toda consulta por la empresa del usuario autenticado. Sin usuario
 * autenticado (login, comandos artisan) no filtra — cada caller decide el
 * alcance explícitamente. Un super-admin autenticado (sin empresa fija) SÍ
 * filtra, a `empresa_id = NULL`: no ve nada de ninguna empresa por esta vía,
 * a propósito — su acceso a datos de negocio es siempre cero fuera del panel
 * de super-admin (que opera sobre el modelo Empresa, sin este scope).
 *
 * Guardia de reentrada: `Usuario` también usa este scope, y `Auth::user()`
 * reconstruye el `Usuario` autenticado (p. ej. `EloquentUserProvider::retrieveById`
 * al restaurar la sesión) consultando ese mismo modelo — sin la guardia, esa
 * consulta vuelve a disparar este `apply()`, que vuelve a pedir `Auth::user()`,
 * en recursión infinita hasta agotar memoria. Con la guardia, esa consulta
 * interna de auto-resolución queda sin filtrar (busca por id/sesión, no hay
 * nada que aislar todavía) y el filtro normal se aplica igual una vez resuelto.
 */
class EmpresaScope implements Scope
{
    private static bool $resolviendoUsuarioAutenticado = false;

    public function apply(Builder $consulta, Model $modelo): void
    {
        if (self::$resolviendoUsuarioAutenticado) {
            return;
        }

        self::$resolviendoUsuarioAutenticado = true;

        try {
            if (! Auth::check()) {
                return;
            }

            $usuario = Auth::user();

            // Fase 4: el superadmin con acceso de soporte activo ve solo esa empresa, en lectura.
            // Solo atributos crudos: ni el accesor `es_superadmin` ni la relación `rol` se pueden
            // leer aquí — ambos vuelven a disparar este scope mientras se está evaluando.
            // La base garantiza sin empresa ⇔ rol Super Admin (ver Usuario::esSuperadmin).
            $esSuperadmin = $usuario->empresa_id === null;

            if ($esSuperadmin) {
                $acceso = app(AccesoSoporteService::class)->accesoActivo($usuario);

                $consulta->where($modelo->getTable().'.empresa_id', $acceso?->empresa_id);
            } else {
                $consulta->where($modelo->getTable().'.empresa_id', $usuario->empresa_id);
            }
        } finally {
            self::$resolviendoUsuarioAutenticado = false;
        }
    }
}
