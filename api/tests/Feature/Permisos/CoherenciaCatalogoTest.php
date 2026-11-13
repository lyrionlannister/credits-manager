<?php

namespace Tests\Feature\Permisos;

use App\Usuarios\Constants\PermisoConstants;
use Database\Seeders\PermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

/**
 * Fase 6: el catálogo, las rutas y la UI hablan del mismo permiso. Un permiso usado en una ruta
 * y ausente del catálogo no se puede asignar (causa `permiso_no_catalogado`); uno del catálogo
 * sin etiqueta se vería como clave cruda en el panel.
 */
class CoherenciaCatalogoTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    /** @return list<string> permisos "modulo.accion" usados en las rutas */
    private function permisosEnRutas(): array
    {
        $rutas = file_get_contents(base_path('routes/api.php'));
        $permisos = [];

        preg_match_all('/(?:permiso|permiso_o_superadmin):([a-z0-9_]+),([a-z0-9_]+)/', $rutas, $simples);
        foreach ($simples[1] as $i => $modulo) {
            $permisos[] = "{$modulo}.{$simples[2][$i]}";
        }

        preg_match_all('/algun_permiso:([a-z0-9_.,]+)/', $rutas, $multiples);
        foreach ($multiples[1] as $lista) {
            foreach (explode(',', $lista) as $clave) {
                $permisos[] = trim($clave);
            }
        }

        return array_values(array_unique(array_filter($permisos)));
    }

    public function test_todo_permiso_usado_en_rutas_esta_en_el_catalogo(): void
    {
        $faltantes = array_diff($this->permisosEnRutas(), array_keys(PermisoConstants::ACCIONES));

        $this->assertSame([], array_values($faltantes), 'Permisos usados en rutas y ausentes del catálogo.');
    }

    public function test_catalogo_del_seeder_y_etiquetas_coinciden_sin_sobrantes(): void
    {
        $delSeeder = [];
        foreach (PermisoSeeder::CATALOGO as $modulo => $acciones) {
            foreach ($acciones as $accion) {
                $delSeeder[] = "{$modulo}.{$accion}";
            }
        }

        $this->assertSame([], array_values(array_diff($delSeeder, array_keys(PermisoConstants::ACCIONES))));
        $this->assertSame([], array_values(array_diff(array_keys(PermisoConstants::ACCIONES), $delSeeder)));
    }

    public function test_get_permisos_devuelve_todo_el_catalogo_con_etiquetas(): void
    {
        $this->seed(PermisoSeeder::class);
        $this->actingAsAdmin();

        $respuesta = $this->getJson('/api/v1/permisos')->assertOk();
        $permisos = $respuesta->json('permisos');

        $this->assertCount(count(PermisoConstants::ACCIONES), $permisos);

        foreach ($permisos as $permiso) {
            $clave = "{$permiso['modulo']}.{$permiso['accion']}";
            $this->assertArrayHasKey($clave, PermisoConstants::ACCIONES);
            $this->assertNotSame($clave, $permiso['accion_etiqueta']);
            $this->assertNotEmpty($permiso['accion_etiqueta']);
        }
    }
}
