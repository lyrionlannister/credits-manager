<?php

namespace App\Usuarios\Constants;

/** Constantes del caché de permisos efectivos y de su invalidación por eventos (Fase 9). */
class PermisoCacheConstants
{
    /** Stream de Redis con los cambios de permisos (mismo cliente de streams que la auditoría). */
    public const STREAM = 'permisos:eventos';

    /** Grupo de consumidores del stream. */
    public const GRUPO = 'escucha_permisos';

    /** TTL de cada llave, solo como red de seguridad: la invalidación normal es el evento. */
    public const TTL_SEGUNDOS = 600;

    public static function llave(int $usuarioId, ?int $empresaId): string
    {
        return "perms:{$usuarioId}:".($empresaId ?? 'sin-empresa');
    }
}
