<?php

namespace App\Monitoreo\Constants;

class TareaProgramadaConstants
{
    /** Catálogo fijo: la única fuente de verdad son estas 7 claves, igual en las migraciones y en `routes/console.php`. */
    public const CLAVES = [
        'cuotas:actualizar-vencidas',
        'respaldos:ejecutar',
        'exportaciones:limpiar',
        'importaciones:limpiar',
        'metas:evaluar',
        'obligaciones_tributarias:evaluar-alertas',
        'contabilidad:devengar-intereses',
    ];

    /** Hora sembrada por la migración y respaldo si la tabla no existe o la base no responde. */
    public const HORAS_POR_DEFECTO = [
        'cuotas:actualizar-vencidas' => '00:05',
        'respaldos:ejecutar' => '01:00',
        'exportaciones:limpiar' => '03:00',
        'importaciones:limpiar' => '03:15',
        'metas:evaluar' => '23:55',
        'obligaciones_tributarias:evaluar-alertas' => '06:00',
        'contabilidad:devengar-intereses' => '02:00',
        'permisos:vencer-excepciones' => '02:30',
    ];

    /** El respaldo automático nunca se pausa desde la UI. */
    public const CLAVE_NO_PAUSABLE = 'respaldos:ejecutar';

    public const CACHE_HORARIOS = 'tareas_programadas:horarios';
}
