<?php

namespace App\Usuarios\Commands;

use App\Usuarios\Services\EscuchaPermisos;
use Illuminate\Console\Command;

class EscucharPermisosCommand extends Command
{
    protected $signature = 'permisos:escuchar {--consumidor= : Nombre del consumidor del grupo (por defecto, el hostname)}';

    protected $description = 'Listener de larga duración: lee el stream de cambios de permisos de Redis e invalida el caché de cada usuario afectado';

    public function handle(EscuchaPermisos $escucha): int
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);

            foreach ([SIGTERM, SIGINT, SIGQUIT] as $senal) {
                pcntl_signal($senal, fn () => $escucha->solicitarParada());
            }
        }

        $escucha->iniciar($this->option('consumidor') ?: (gethostname() ?: 'escucha-'.getmypid()));

        return self::SUCCESS;
    }
}
