<?php

namespace Tests\Feature\Empresas;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class EmpresaLogoTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_subir_un_png_no_genera_version_para_pdf(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $this->postJson('/api/v1/empresa/logo', ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertOk()
            ->assertJsonPath('empresa.logo_pdf_path', null);
    }

    public function test_subir_un_svg_genera_un_png_rasterizado_para_el_pdf(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>',
        );

        $empresa = $this->postJson('/api/v1/empresa/logo', ['logo' => $svg])->assertOk()->json('empresa');

        $this->assertStringEndsWith('.svg', $empresa['logo_path']);
        $this->assertNotNull($empresa['logo_pdf_path']);
        $this->assertStringEndsWith('.png', $empresa['logo_pdf_path']);
        Storage::disk('public')->assertExists(str_replace('storage/', '', $empresa['logo_pdf_path']));
    }

    public function test_sin_sesion_no_hay_respuesta(): void
    {
        $this->postJson('/api/v1/empresa/logo', ['logo' => UploadedFile::fake()->image('logo.png')])->assertUnauthorized();
    }

    public function test_el_comando_de_backfill_rasteriza_los_logos_svg_subidos_antes_del_fix(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put(
            'logos/legado.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>',
        );
        $empresa = $this->crearEmpresaDePrueba(['logo_path' => 'storage/logos/legado.svg']);

        $this->artisan('empresas:rasterizar-logos-pendientes')->assertExitCode(0);

        $this->assertNotNull($empresa->refresh()->logo_pdf_path);
        Storage::disk('public')->assertExists(str_replace('storage/', '', $empresa->logo_pdf_path));
    }
}
