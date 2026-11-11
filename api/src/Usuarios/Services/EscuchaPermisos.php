<?php

namespace App\Usuarios\Services;

use App\Auditoria\Constants\AuditoriaConstants;
use App\Redis\Contracts\RedisClienteInterface;
use App\Redis\Exceptions\RedisClienteException;
use App\Usuarios\Constants\PermisoCacheConstants;
use Illuminate\Support\Facades\Log;

/**
 * Consumidor del stream de permisos (Fase 9): mismo patrón que el listener de auditoría
 * (consumer group + XREADGROUP BLOCK + XAUTOCLAIM + XACK). Cada evento `permissions.changed`
 * olvida la llave de ese usuario en este proceso; la invalidación síncrona ya se hizo al
 * guardar el cambio, así que este listener solo sincroniza los demás procesos.
 */
class EscuchaPermisos
{
    private bool $detener = false;

    private float $esperaInicial = 1.0;

    private float $ultimaReclamacion = 0.0;

    public function __construct(
        protected RedisClienteInterface $redis,
        protected PermisoCache $cache,
    ) {}

    public function solicitarParada(): void
    {
        $this->detener = true;
    }

    public function iniciar(string $consumidor): void
    {
        $this->detener = false;

        $this->prepararGrupo();

        Log::info('Listener de permisos iniciado.', ['consumidor' => $consumidor]);

        $espera = $this->esperaInicial;

        while (! $this->detener) {
            try {
                $this->ciclo($consumidor, AuditoriaConstants::BLOQUEO_MS);
                $espera = $this->esperaInicial;
            } catch (RedisClienteException $excepcion) {
                Log::error('Listener de permisos: Redis no responde, se reintenta.', ['error' => $excepcion->getMessage()]);

                $this->dormir($espera);
                $espera = min($espera * 2, AuditoriaConstants::ESPERA_MAXIMA_SEGUNDOS);

                $this->intentar(fn () => $this->redis->crearGrupo(PermisoCacheConstants::STREAM, PermisoCacheConstants::GRUPO));
            }
        }

        Log::info('Listener de permisos detenido.', ['consumidor' => $consumidor]);
    }

    public function ciclo(string $consumidor, int $bloqueoMs): int
    {
        $procesadas = 0;

        if (microtime(true) - $this->ultimaReclamacion >= AuditoriaConstants::RECLAMAR_INACTIVAS_MS / 1000) {
            $this->ultimaReclamacion = microtime(true);

            $procesadas += $this->procesarLote(
                $this->redis->reclamarInactivas(PermisoCacheConstants::STREAM, PermisoCacheConstants::GRUPO, $consumidor, AuditoriaConstants::RECLAMAR_INACTIVAS_MS, AuditoriaConstants::LOTE),
            );
        }

        return $procesadas + $this->procesarLote(
            $this->redis->leerNuevas(PermisoCacheConstants::STREAM, PermisoCacheConstants::GRUPO, $consumidor, AuditoriaConstants::LOTE, $bloqueoMs),
        );
    }

    /** @param array<string, array<string, string>> $entradas */
    private function procesarLote(array $entradas): int
    {
        $procesadas = 0;

        foreach ($entradas as $id => $campos) {
            if ($this->detener) {
                break;
            }

            try {
                $this->cache->procesarEvento($campos);
                $this->redis->confirmar(PermisoCacheConstants::STREAM, PermisoCacheConstants::GRUPO, [(string) $id]);
                $procesadas++;
            } catch (RedisClienteException $excepcion) {
                Log::error('Listener de permisos: no se pudo confirmar una entrada.', ['id' => $id, 'error' => $excepcion->getMessage()]);
            }
        }

        return $procesadas;
    }

    private function prepararGrupo(): void
    {
        $this->intentar(fn () => $this->redis->crearGrupo(PermisoCacheConstants::STREAM, PermisoCacheConstants::GRUPO));
    }

    private function intentar(callable $operacion): mixed
    {
        try {
            return $operacion();
        } catch (RedisClienteException $excepcion) {
            Log::warning('Listener de permisos: operación de Redis fallida.', ['error' => $excepcion->getMessage()]);

            return null;
        }
    }

    private function dormir(float $segundos): void
    {
        if ($segundos > 0) {
            usleep((int) ($segundos * 1_000_000));
        }
    }
}
