<?php

namespace App\Empresas\Services;

use App\Auditoria\Enums\AccionAuditoria;
use App\Auditoria\Services\EmisorAuditoria;
use App\Contabilidad\Repositories\TipoDocumentoNegocioRepository;
use App\Empresas\Constants\EmpresaConstants;
use App\Empresas\Models\Empresa;
use App\Empresas\Repositories\EmpresaRepository;
use App\Facturacion\Repositories\ParametrizacionConceptoFacturacionRepository;
use App\Gastos\Repositories\ActividadEconomicaRepository;
use App\Usuarios\Constants\UsuarioConstants;
use App\Usuarios\Models\Permiso;
use App\Usuarios\Models\Rol;
use App\Usuarios\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class EmpresaService
{
    public function __construct(
        protected EmpresaRepository $empresas,
        protected ParametrizacionConceptoFacturacionRepository $parametrizacionesConcepto,
        protected TipoDocumentoNegocioRepository $tiposDocumentoNegocio,
        protected ActividadEconomicaRepository $actividadesEconomicas,
        protected EmisorAuditoria $auditoria,
    ) {}

    /** Panel de super-admin: todas las empresas de la plataforma. */
    public function listarEmpresas(?string $busqueda, int $porPagina, int $pagina): LengthAwarePaginator
    {
        return $this->empresas->paginar($busqueda, max(1, min(100, $porPagina)), max(1, $pagina));
    }

    /** La empresa del usuario autenticado (autoservicio). */
    public function obtenerPropia(): Empresa
    {
        return $this->conMunicipio($this->empresas->buscarPorIdOFallar(Auth::user()->empresa_id));
    }

    /** Como `obtenerPropia`, pero `null` si el usuario no pertenece a una empresa (superadmin). */
    public function obtenerPropiaOpcional(): ?Empresa
    {
        $empresaId = Auth::user()->empresa_id;

        return $empresaId === null ? null : $this->conMunicipio($this->empresas->buscarPorIdOFallar($empresaId));
    }

    /** Con el municipio, su estado y la actividad económica, lo que el formulario necesita para mostrar lo ya elegido. */
    public function conMunicipio(Empresa $empresa): Empresa
    {
        return $empresa->load(['municipio:id,estado_id,nombre', 'municipio.estado:id,pais_id,nombre', 'actividadEconomica:id,codigo,nombre']);
    }

    /** Crea una empresa nueva junto con su primer usuario admin y sus roles base. */
    public function crearEmpresaConAdmin(array $datos): Empresa
    {
        return DB::transaction(function () use ($datos) {
            $empresa = $this->empresas->crear([
                'nombre_empresa' => $datos['nombre_empresa'],
                'nit' => $datos['nit'],
                'digito_verificacion' => $datos['digito_verificacion'],
                'direccion' => $datos['direccion'],
                'municipio_id' => $datos['municipio_id'],
                'responsable_iva' => $datos['responsable_iva'] ?? false,
                'autorretenedor' => $datos['autorretenedor'] ?? false,
                'telefono' => $datos['telefono'],
                'correo' => $datos['correo'],
            ]);

            $this->parametrizacionesConcepto->crearPorDefectoParaEmpresa($empresa->id);
            $this->tiposDocumentoNegocio->crearPorDefectoParaEmpresa($empresa->id);

            if (! empty($datos['actividad_economica_codigo'])) {
                $actividadEconomica = $this->actividadesEconomicas->crear([
                    'empresa_id' => $empresa->id,
                    'codigo' => $datos['actividad_economica_codigo'],
                    'nombre' => $datos['actividad_economica_nombre'],
                    'activo' => true,
                ]);

                $empresa = $this->empresas->actualizar($empresa, ['actividad_economica_id' => $actividadEconomica->id]);
            }

            $rolAdmin = $this->crearRolesBase($empresa)[UsuarioConstants::ROL_ADMIN];

            Usuario::create([
                'empresa_id' => $empresa->id,
                'nombre' => $datos['admin_nombre'],
                'usuario' => strtolower($datos['admin_usuario']),
                'password_hash' => $datos['admin_password'],
                'rol_id' => $rolAdmin->id,
                'activo' => true,
            ]);

            $this->auditoria->registrar(
                AccionAuditoria::Crear,
                'empresas',
                "Empresa {$empresa->nombre_empresa} creada con su administrador {$datos['admin_usuario']}",
                'empresa',
                $empresa->id,
                nuevos: $empresa->only(['nombre_empresa', 'nit', 'digito_verificacion', 'direccion', 'municipio_id', 'actividad_economica_id', 'telefono', 'correo']) + ['admin_usuario' => strtolower($datos['admin_usuario'])],
            );

            return $this->conMunicipio($empresa);
        });
    }

    public function editarEmpresa(Empresa $empresa, array $datos): Empresa
    {
        $anteriores = $empresa->only(array_keys($datos));

        $empresa = $this->empresas->actualizar($empresa, $datos);

        $nuevos = $empresa->only(array_keys($datos));
        $cambiados = array_keys(array_diff_assoc($nuevos, $anteriores));

        if ($cambiados !== []) {
            $this->auditoria->registrar(
                AccionAuditoria::Modificar,
                'empresas',
                "Empresa {$empresa->nombre_empresa} modificada",
                'empresa',
                $empresa->id,
                anteriores: array_intersect_key($anteriores, array_flip($cambiados)),
                nuevos: array_intersect_key($nuevos, array_flip($cambiados)),
            );
        }

        return $this->conMunicipio($empresa);
    }

    public function actualizarLogoPropia(UploadedFile $logo): Empresa
    {
        $empresa = $this->obtenerPropia();

        foreach ([$empresa->logo_path, $empresa->logo_pdf_path] as $anterior) {
            if ($anterior) {
                Storage::disk('public')->delete(str_replace('storage/', '', $anterior));
            }
        }

        $anteriores = $empresa->only(['logo_path', 'logo_pdf_path']);
        $path = $logo->store('logos', 'public');
        $pathPdf = $logo->getMimeType() === 'image/svg+xml' ? $this->rasterizarLogoParaPdf($path) : null;

        $empresa = $this->empresas->actualizar($empresa, [
            'logo_path' => 'storage/'.$path,
            'logo_pdf_path' => $pathPdf ? 'storage/'.$pathPdf : null,
        ]);

        $nuevos = $empresa->only(['logo_path', 'logo_pdf_path']);
        $cambiados = array_keys(array_diff_assoc($nuevos, $anteriores));

        $this->auditoria->registrar(
            AccionAuditoria::Modificar,
            'empresas',
            "Logo de la empresa {$empresa->nombre_empresa} actualizado",
            'empresa',
            $empresa->id,
            anteriores: array_intersect_key($anteriores, array_flip($cambiados)),
            nuevos: array_intersect_key($nuevos, array_flip($cambiados)),
        );

        return $empresa;
    }

    /** Backfill para empresas que subieron su logo SVG antes de que existiera `logo_pdf_path`. */
    public function rasterizarLogoPendiente(Empresa $empresa): bool
    {
        if ($empresa->logo_pdf_path || ! $empresa->logo_path || ! str_ends_with($empresa->logo_path, '.svg')) {
            return false;
        }

        $pathPdf = $this->rasterizarLogoParaPdf(Str::after($empresa->logo_path, 'storage/'));

        if (! $pathPdf) {
            return false;
        }

        $this->empresas->actualizar($empresa, ['logo_pdf_path' => 'storage/'.$pathPdf]);

        return true;
    }

    /** El SVG se ve mejor en la web, pero dompdf lo pinta con fondo negro: para el PDF se rasteriza a PNG con rsvg-convert. */
    private function rasterizarLogoParaPdf(string $pathSvg): ?string
    {
        $disco = Storage::disk('public');
        $pathPng = Str::replaceLast('.svg', '.png', $pathSvg);

        $proceso = new Process(['rsvg-convert', '--width', '480', $disco->path($pathSvg), '-o', $disco->path($pathPng)]);
        $proceso->run();

        return $proceso->isSuccessful() ? $pathPng : null;
    }

    /** @return array<string, Rol> roles creados, indexados por nombre */
    private function crearRolesBase(Empresa $empresa): array
    {
        $idPorClave = Permiso::query()->get(['id', 'modulo', 'accion'])
            ->mapWithKeys(fn (Permiso $permiso) => ["{$permiso->modulo}.{$permiso->accion}" => $permiso->id]);

        $roles = [];

        foreach (EmpresaConstants::ROLES_BASE as $nombre => $descripcion) {
            $rol = Rol::create([
                'empresa_id' => $empresa->id,
                'nombre' => $nombre,
                'descripcion' => $descripcion,
            ]);

            $clavesPermisos = $nombre === UsuarioConstants::ROL_ADMIN
                ? $idPorClave->keys()->all()
                : (EmpresaConstants::PERMISOS_POR_ROL[$nombre] ?? []);

            $rol->permisos()->sync(collect($clavesPermisos)->map(fn (string $clave) => $idPorClave[$clave])->all());

            $roles[$nombre] = $rol;
        }

        return $roles;
    }
}
