<?php

namespace App\Notificaciones\Enums;

enum TipoNotificacion: string
{
    case ExportacionLista = 'exportacion_lista';
    case ExportacionFallida = 'exportacion_fallida';
    case ReaperturaSolicitada = 'reapertura_solicitada';
    case ReaperturaAutorizada = 'reapertura_autorizada';
    case ReaperturaRechazada = 'reapertura_rechazada';
    case ImportacionValidada = 'importacion_validada';
    case ImportacionCompletada = 'importacion_completada';
    case ImportacionFallida = 'importacion_fallida';
    case ExtractoBancarioCargado = 'extracto_bancario_cargado';
    case ExtractoBancarioRechazado = 'extracto_bancario_rechazado';
    case ObligacionTributariaPorVencer = 'obligacion_tributaria_por_vencer';
    case AccesoSoporteSolicitado = 'acceso_soporte_solicitado';
    case AccesoSoporteAprobado = 'acceso_soporte_aprobado';
    case AccesoSoporteRechazado = 'acceso_soporte_rechazado';
    case AccesoSoporteRevocado = 'acceso_soporte_revocado';
}
