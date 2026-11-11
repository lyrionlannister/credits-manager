<?php

namespace App\Soporte\Commands;

use App\Soporte\Services\AccesoSoporteService;
use Illuminate\Console\Command;

class ExpirarAccesosSoporteCommand extends Command
{
    protected $signature = 'soporte:expirar-accesos';

    protected $description = 'Marca como expirados los accesos de soporte just-in-time vencidos.';

    public function handle(AccesoSoporteService $accesos): int
    {
        $expirados = $accesos->expirarVencidos();

        $this->info("Accesos de soporte expirados: {$expirados}.");

        return self::SUCCESS;
    }
}
