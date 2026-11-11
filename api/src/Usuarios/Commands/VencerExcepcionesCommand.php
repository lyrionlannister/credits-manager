<?php

namespace App\Usuarios\Commands;

use App\Usuarios\Services\VencimientoExcepcionesService;
use Illuminate\Console\Command;

class VencerExcepcionesCommand extends Command
{
    protected $signature = 'permisos:vencer-excepciones';

    protected $description = 'Revoca las excepciones individuales de permiso vencidas: al vencer, el rol vuelve a mandar';

    public function handle(VencimientoExcepcionesService $vencimiento): int
    {
        $vencidas = $vencimiento->vencer();

        $this->info("Excepciones vencidas: {$vencidas}.");

        return self::SUCCESS;
    }
}
