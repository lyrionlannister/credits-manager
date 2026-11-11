<?php

namespace App\Usuarios\Services;

use App\Redis\Contracts\RedisClienteInterface;
use App\Usuarios\Constants\PermisoCacheConstants;
use App\Usuarios\Models\Usuario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Caché del permiso efectivo por (usuario, empresa) (Fase 9, decisión de Luis 2026-11-10):
 * NO es una foto al login — se invalida por eventos. Cada cambio de permisos olvida la llave en
 * el acto y emite `permissions.changed` al stream `permisos:eventos` (tras el commit) para que los
 * demás procesos la olviden también. El TTL (10 min) es solo red de seguridad. Si Redis cae, se
 * calcula contra la base de datos: nunca fail-open, nunca se niega por falta de caché.
 */
class PermisoCache
{
    public function __construct(protected RedisClienteInterface $redis) {}

    /** @return list<string> permisos efectivos "modulo.accion", ordenados */
    public function efectivos(Usuario $usuario): array
    {
        try {
            $llave = PermisoCacheConstants::llave((int) $usuario->id, $usuario->empresa_id);

            $enCache = Cache::get($llave);

            if (is_array($enCache)) {
                return $enCache;
            }

            $efectivos = $this->calcular($usuario);

            Cache::put($llave, $efectivos, PermisoCacheConstants::TTL_SEGUNDOS);

            return $efectivos;
        } catch (Throwable $excepcion) {
            Log::warning('Caché de permisos no disponible: se calcula contra la base de datos.', [
                'usuario_id' => $usuario->id,
                'error' => $excepcion->getMessage(),
            ]);

            return $this->calcular($usuario);
        }
    }

    /**
     * Invalida la llave en este proceso y avisa a los demás con el evento (tras el commit).
     * Llamar siempre que cambien el rol o las excepciones de un usuario.
     */
    public function invalidar(Usuario $usuario): void
    {
        $this->olvidar((int) $usuario->id, $usuario->empresa_id);

        $usuarioId = (int) $usuario->id;
        $empresaId = $usuario->empresa_id;
        $version = $usuario->version_permisos ?? 1;

        DB::afterCommit(function () use ($usuarioId, $empresaId, $version) {
            try {
                $this->redis->agregar(PermisoCacheConstants::STREAM, [
                    'tipo' => 'permissions.changed',
                    'usuario_id' => (string) $usuarioId,
                    'empresa_id' => (string) ($empresaId ?? 0),
                    'version' => (string) $version,
                ]);
            } catch (Throwable $excepcion) {
                // El olvido síncrono ya se hizo: el evento solo sincroniza otros procesos.
                Log::warning('No se pudo emitir permissions.changed.', ['usuario_id' => $usuarioId, 'error' => $excepcion->getMessage()]);
            }
        });
    }

    /** Olvida la llave, sin lanzar si el caché no responde. */
    public function olvidar(int $usuarioId, ?int $empresaId): void
    {
        try {
            Cache::forget(PermisoCacheConstants::llave($usuarioId, $empresaId));
        } catch (Throwable $excepcion) {
            Log::warning('No se pudo olvidar el caché de permisos.', ['usuario_id' => $usuarioId, 'error' => $excepcion->getMessage()]);
        }
    }

    /** Lo que hace el listener con cada evento del stream: olvidar la llave indicada. */
    public function procesarEvento(array $campos): void
    {
        if (($campos['tipo'] ?? '') !== 'permissions.changed') {
            return;
        }

        $this->olvidar((int) ($campos['usuario_id'] ?? 0), (int) ($campos['empresa_id'] ?? 0) ?: null);
    }

    /** @return list<string> */
    private function calcular(Usuario $usuario): array
    {
        $efectivos = $usuario->rol?->permisos()->get()
            ->map(fn ($permiso) => "{$permiso->modulo}.{$permiso->accion}")
            ->all() ?? [];

        foreach ($usuario->permisosExtraVigentes()->get() as $permiso) {
            $clave = "{$permiso->modulo}.{$permiso->accion}";

            if ($permiso->pivot->concedido) {
                $efectivos[] = $clave;
            } else {
                $efectivos = array_values(array_diff($efectivos, [$clave]));
            }
        }

        sort($efectivos);

        return array_values(array_unique($efectivos));
    }
}
