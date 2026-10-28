<?php

namespace Tests\Feature\Importacion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\ImportaArchivos;
use Tests\TestCase;

class ImportacionValoresUvtTest extends TestCase
{
    use ActsAsAdmin;
    use ImportaArchivos;
    use RefreshDatabase;

    private const ENCABEZADO = "Fecha desde;Valor UVT\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararImportaciones();
    }

    public function test_camino_feliz_crea_el_valor_vigente_desde_la_fecha(): void
    {
        $this->actingAsAdmin();

        $id = $this->importarCompleto('valores_uvt', self::ENCABEZADO."01/01/2026;47065\n");

        $this->assertSame(1, $this->importacion($id)->filas_aceptadas);
        $this->assertDatabaseHas('valores_uvt', ['fecha_desde' => '2026-01-01', 'fecha_hasta' => null]);
    }

    /** Nunca sobrescribe: reimportar una fecha posterior cierra la vigencia abierta anterior. */
    public function test_reimportar_con_una_fecha_posterior_cierra_la_vigencia_anterior(): void
    {
        $this->actingAsAdmin();

        $this->importarCompleto('valores_uvt', self::ENCABEZADO."01/01/2026;47065\n");
        $this->importarCompleto('valores_uvt', self::ENCABEZADO."01/07/2026;49799\n");

        $this->assertDatabaseCount('valores_uvt', 2);
        $this->assertDatabaseHas('valores_uvt', ['fecha_desde' => '2026-01-01', 'fecha_hasta' => '2026-06-30']);
        $this->assertDatabaseHas('valores_uvt', ['fecha_desde' => '2026-07-01', 'fecha_hasta' => null, 'valor' => 4979900]);
    }

    public function test_un_valor_en_cero_se_rechaza(): void
    {
        $this->actingAsAdmin();

        $id = $this->importarCompleto('valores_uvt', self::ENCABEZADO."01/01/2026;0\n");

        $this->assertSame(0, $this->importacion($id)->filas_aceptadas);
        $this->assertStringContainsString('mayor que cero', implode(' ', $this->motivosDeRechazo($id)));
    }
}
