<?php

namespace App\Impresion\Data;

/** Qué datos de la empresa van en el encabezado; por defecto, todos (el comportamiento de siempre). */
final readonly class CamposEmpresaDocumento
{
    public function __construct(
        public bool $logo = true,
        public bool $nombre = true,
        public bool $nit = true,
        public bool $direccion = true,
        public bool $telefono = true,
        public bool $ubicacion = true,
        public bool $correo = true,
    ) {}

    public static function todos(): self
    {
        return new self;
    }
}
