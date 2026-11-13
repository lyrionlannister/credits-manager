<?php

namespace App\Soporte\Enums;

enum EstadoAccesoSoporte: string
{
    case Solicitado = 'solicitado';
    case Aprobado = 'aprobado';
    case Activo = 'activo';
    case Expirado = 'expirado';
    case Revocado = 'revocado';
    case Rechazado = 'rechazado';
}
