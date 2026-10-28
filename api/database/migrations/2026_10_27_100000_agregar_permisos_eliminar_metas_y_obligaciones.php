<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill de datos para empresas ya existentes, igual que `agregar_permiso_dashboard_contable`: los permisos nuevos de
 * eliminación solo llegan solos a los roles creados a partir de ahora. Idempotente.
 */
return new class extends Migration
{
    /** "modulo.accion" => roles de empresa que lo reciben. */
    private const PERMISOS = [
        'metas.eliminar' => ['admin'],
        'obligaciones_tributarias.eliminar' => ['admin', 'contable'],
        'obligaciones_tributarias.eliminar_tipos' => ['admin', 'contable'],
    ];

    public function up(): void
    {
        foreach (self::PERMISOS as $clave => $roles) {
            [$modulo, $accion] = explode('.', $clave);
            $permisoId = $this->asegurarPermiso($modulo, $accion);

            $rolesDeEmpresa = DB::table('roles')->whereNotNull('empresa_id')->whereIn('nombre', $roles)->pluck('id');
            $yaAsignados = DB::table('rol_permiso')->where('permiso_id', $permisoId)->whereIn('rol_id', $rolesDeEmpresa)->pluck('rol_id');

            $filas = $rolesDeEmpresa->diff($yaAsignados)->map(fn (int $rolId) => [
                'rol_id' => $rolId,
                'permiso_id' => $permisoId,
                'creado_en' => now(),
                'ultima_actualizacion' => now(),
            ]);

            if ($filas->isNotEmpty()) {
                DB::table('rol_permiso')->insert($filas->values()->all());
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::PERMISOS) as $clave) {
            [$modulo, $accion] = explode('.', $clave);
            $permiso = DB::table('permisos')->where('modulo', $modulo)->where('accion', $accion)->first();

            if ($permiso === null) {
                continue;
            }

            DB::table('rol_permiso')->where('permiso_id', $permiso->id)->delete();
            DB::table('permisos')->where('id', $permiso->id)->update(['borrado_en' => now()]);
        }
    }

    private function asegurarPermiso(string $modulo, string $accion): int
    {
        $permiso = DB::table('permisos')->where('modulo', $modulo)->where('accion', $accion)->first();

        if ($permiso === null) {
            return DB::table('permisos')->insertGetId([
                'modulo' => $modulo,
                'accion' => $accion,
                'creado_en' => now(),
                'ultima_actualizacion' => now(),
            ]);
        }

        if ($permiso->borrado_en !== null) {
            DB::table('permisos')->where('id', $permiso->id)->update(['borrado_en' => null, 'ultima_actualizacion' => now()]);
        }

        return $permiso->id;
    }
};
