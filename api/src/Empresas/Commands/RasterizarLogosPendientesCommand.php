<?php

namespace App\Empresas\Commands;

use App\Empresas\Repositories\EmpresaRepository;
use App\Empresas\Services\EmpresaService;
use Illuminate\Console\Command;

/** Backfill de una sola vez: empresas con logo SVG subido antes de que existiera `logo_pdf_path`. */
class RasterizarLogosPendientesCommand extends Command
{
    protected $signature = 'empresas:rasterizar-logos-pendientes';

    protected $description = 'Genera el PNG para PDF (logo_pdf_path) de las empresas que ya tenían un logo SVG';

    public function handle(EmpresaRepository $empresasRepositorio, EmpresaService $empresas): int
    {
        $pendientes = $empresasRepositorio->conLogoSvgPendienteDeRasterizar();
        $generados = 0;

        foreach ($pendientes as $empresa) {
            if ($empresas->rasterizarLogoPendiente($empresa)) {
                $generados++;
                $this->line("Rasterizado: {$empresa->nombre_empresa}");
            }
        }

        $this->info("Logos generados: {$generados} de {$pendientes->count()} empresas revisadas.");

        return self::SUCCESS;
    }
}
