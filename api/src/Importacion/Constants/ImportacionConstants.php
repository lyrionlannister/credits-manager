<?php

namespace App\Importacion\Constants;

class ImportacionConstants
{
    public const DISCO = 'importaciones';

    /** Conexión de cola explícita, igual que exportaciones: no depende de QUEUE_CONNECTION. */
    public const CONEXION_COLA = 'redis';

    public const COLA = 'importaciones';

    public const TIMEOUT_JOB_SEGUNDOS = 3600;

    /**
     * Un permiso por hoja (`DefinicionImportacion::para($tipo)['permiso']`). La ruta deja pasar con cualquiera de ellos;
     * el servicio exige el de la hoja de cada carga.
     */
    public const PERMISOS_IMPORTAR = [
        'importaciones.importar_clientes', 'importaciones.importar_creditos', 'importaciones.importar_plan_cuotas',
        'importaciones.importar_pagos', 'importaciones.importar_saldos_iniciales', 'importaciones.importar_plan_cuentas',
        'importaciones.importar_terceros', 'importaciones.importar_gastos', 'importaciones.importar_actividades_economicas',
        'importaciones.importar_valores_uvt', 'importaciones.importar_tarifas_reteica',
    ];

    /** Crear empleadores faltantes usa el mismo permiso que el alta de empleadores (`POST /empleadores`). */
    public const PERMISO_EMPLEADORES = ['empleadores', 'crear'];

    /** Contabilizar (desembolsos y recaudos) usa el permiso de comprobantes manuales. */
    public const PERMISO_CONTABILIZAR = ['contabilidad', 'crear_manual'];

    public const ACCION_CONTABILIZAR = 'crear_manual';

    public const ESTADO_PENDIENTE_DECISION = 'pendiente_decision';

    public const MOTIVO_ERROR_INESPERADO = 'No se pudo procesar el archivo por un error inesperado.';

    public const MOTIVO_FILA_INESPERADA = 'No se pudo procesar la fila por un error inesperado.';

    /** Hasta un peso de diferencia la cuota del archivo se considera redondeada (p. ej. a pesos enteros); más allá es otra cuota. */
    public const UMBRAL_REDONDEO_CUOTA_CENTAVOS = 100;

    /** Puntos porcentuales: una mensual escrita con 2 decimales a partir de la EA se acepta como la misma tasa. */
    public const TOLERANCIA_TASA_MENSUAL = 0.005;

    public const TASA_EFECTIVA_ANUAL_MAXIMA = 999;
}
