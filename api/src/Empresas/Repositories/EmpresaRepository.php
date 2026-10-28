<?php

namespace App\Empresas\Repositories;

use App\Core\Base\BaseRepository;
use App\Empresas\Models\Empresa;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class EmpresaRepository extends BaseRepository
{
    public function __construct(Empresa $empresa)
    {
        parent::__construct($empresa);
    }

    public function paginar(?string $busqueda, int $porPagina, int $pagina): LengthAwarePaginator
    {
        $consulta = $this->consulta()->with('municipio:id,nombre')->orderBy('nombre_empresa');

        if ($busqueda) {
            $consulta->where('nombre_empresa', 'like', "%{$busqueda}%");
        }

        return $consulta->paginate(perPage: $porPagina, page: $pagina);
    }

    /** Backfill de todas las empresas, no solo la propia: no hay sesión en el comando artisan que la usa. */
    public function conLogoSvgPendienteDeRasterizar(): Collection
    {
        return $this->consulta()->whereNull('logo_pdf_path')->whereNotNull('logo_path')->where('logo_path', 'like', '%.svg')->get();
    }
}
