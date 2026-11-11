<?php

namespace App\Auditoria\Constants;

class AuditoriaConstants
{
    public const MODULO = 'auditoria';

    public const MODULO_USUARIOS = 'usuarios';

    public const MODULO_CLIENTES = 'clientes';

    public const MODULO_EMPLEADORES = 'empleadores';

    public const MODULO_TERCEROS = 'terceros';

    public const MODULO_PROVEEDORES = 'proveedores';

    public const MODULO_DOCUMENTOS = 'documentos';

    public const MODULO_CREDITOS = 'creditos';

    public const MODULO_PAGOS = 'pagos';

    public const MODULO_CARTERA = 'cartera';

    public const MODULO_CONTABILIDAD = 'contabilidad';

    public const MODULO_GASTOS = 'gastos';

    public const MODULO_EGRESOS = 'egresos';

    public const MODULO_FACTURACION = 'facturacion';

    public const MODULO_CONCILIACION = 'conciliacion';

    public const MODULO_GEOGRAFIA = 'geografia';

    public const MODULO_CORREO = 'correo';

    public const MODULO_SOPORTE = 'soporte';

    /** Tope de ids que un evento masivo (p. ej. mora) lista de forma explícita; el conteo siempre va completo. */
    public const MAXIMO_IDS_EN_EVENTO = 200;

    /** Columnas de tiempo que cambian solas y no aportan al antes/después de un evento. */
    public const COLUMNAS_TIEMPO = ['creado_en', 'ultima_actualizacion', 'borrado_en'];

    public const STREAM = 'auditoria:eventos';

    public const GRUPO = 'escucha_auditoria';

    public const CANAL_LOG_RESPALDO = 'auditoria_respaldo';

    /** Cuánto espera XREADGROUP: solo para revisar SIGTERM y reclamar huérfanos; el evento llega sin demora. */
    public const BLOQUEO_MS = 5000;

    public const LOTE = 100;

    public const RECLAMAR_INACTIVAS_MS = 15000;

    public const INTENTOS_INSERCION = 5;

    public const ESPERA_MAXIMA_SEGUNDOS = 30;

    public const LONGITUD_MODULO = 50;

    public const LONGITUD_ENTIDAD = 60;

    public const LONGITUD_DESCRIPCION = 500;

    public const LONGITUD_TEXTO_AUTOR = 255;

    public const LONGITUD_MOTIVO = 2000;

    public const LONGITUD_IP = 45;

    public const BYTES_MAXIMOS_VALORES = 100000;

    /** Claves que nunca se guardan en valores anteriores/nuevos, a cualquier profundidad. */
    public const CAMPOS_SENSIBLES = ['password', 'password_hash', 'clave', 'clave_actual', 'clave_nueva', 'token', 'remember_token'];

    public const COLUMNAS_ORDENABLES = ['fecha_hora', 'modulo', 'accion'];
}
