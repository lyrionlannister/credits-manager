<?php

namespace Tests\Feature\Soporte;

use App\Empresas\Models\Empresa;
use App\Soporte\Models\AccesoSoporte;
use App\Usuarios\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\AfirmaAuditoria;
use Tests\Concerns\CreatesAuditoriaFixtures;
use Tests\TestCase;

/**
 * Fase 4: el superadmin no tiene bypass general; solo ve los datos de una empresa con un acceso
 * de soporte just-in-time aprobado por un admin de esa empresa, solo en lectura y con vencimiento.
 */
class AccesoSoporteTest extends TestCase
{
    use ActsAsAdmin;
    use AfirmaAuditoria;
    use CreatesAuditoriaFixtures;
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $admin;

    private Usuario $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        // La base admite un solo Super Admin vivo: se crea una vez y se reutiliza en todo el test.
        $this->admin = $this->actingAsAdmin();
        $this->empresa = Empresa::findOrFail($this->admin->empresa_id);
        $this->superadmin = $this->actingAsSuperAdmin();
    }

    private function comoSuperAdmin(): void
    {
        $this->actingAs($this->superadmin, 'sanctum');
    }

    private function comoAdmin(): void
    {
        $this->actingAs($this->admin, 'sanctum');
    }

    private function solicitar(): array
    {
        $this->comoSuperAdmin();

        return $this->postJson('/api/v1/accesos-soporte', [
            'empresa_id' => $this->empresa->id,
            'motivo' => 'Revisión de un reporte',
            'duracion_minutos' => 30,
        ])->assertCreated()->json('acceso');
    }

    private function aprobar(int $accesoId): void
    {
        $this->comoAdmin();
        $this->postJson("/api/v1/accesos-soporte/{$accesoId}/aprobar")->assertOk();
    }

    public function test_sin_acceso_activo_el_superadmin_no_ve_datos_de_ninguna_empresa(): void
    {
        $this->comoSuperAdmin();

        $this->getJson('/api/v1/clientes')
            ->assertForbidden()
            ->assertJsonPath('motivo_denegacion', 'superadmin_sin_bypass');
    }

    public function test_flujo_completo_solicitar_aprobar_activar_leer_y_revocar(): void
    {
        $acceso = $this->solicitar();
        $this->assertEventoAuditoria('crear', 'soporte', 'acceso_soporte');

        // El superadmin aún no puede leer: falta la aprobación y la activación.
        $this->comoSuperAdmin();
        $this->getJson('/api/v1/clientes')->assertForbidden();

        $this->aprobar((int) $acceso['id']);
        $this->assertEventoAuditoria('aprobar', 'soporte', 'acceso_soporte');

        $this->comoSuperAdmin();
        $this->postJson("/api/v1/accesos-soporte/{$acceso['id']}/activar")
            ->assertOk()
            ->assertJsonPath('acceso.estado', 'activo');

        // Lectura permitida solo en esa empresa…
        $this->getJson('/api/v1/clientes')->assertOk();

        // …pero escribir y exportar siguen denegados (el middleware de solo lectura actúa antes que el de exportación).
        $this->postJson('/api/v1/clientes', [])->assertForbidden()
            ->assertJsonPath('motivo_denegacion', 'acceso_soporte_solo_lectura');
        $this->postJson('/api/v1/clientes/exportar', [])->assertForbidden()
            ->assertJsonPath('motivo_denegacion', 'acceso_soporte_solo_lectura');

        // El admin de la empresa revoca y la lectura se cierra de inmediato.
        $this->comoAdmin();
        $this->postJson("/api/v1/accesos-soporte/{$acceso['id']}/revocar")
            ->assertOk()
            ->assertJsonPath('acceso.estado', 'revocado');

        $this->comoSuperAdmin();
        $this->getJson('/api/v1/clientes')->assertForbidden();
    }

    public function test_un_admin_de_otra_empresa_no_puede_aprobar(): void
    {
        $acceso = $this->solicitar();
        $otraEmpresa = $this->crearEmpresaDePrueba();

        $this->actingAsRolConPermisos('admin', [], $otraEmpresa);

        $this->postJson("/api/v1/accesos-soporte/{$acceso['id']}/aprobar")->assertForbidden();
    }

    public function test_el_acceso_vencido_deja_de_servir(): void
    {
        $acceso = $this->solicitar();
        $this->aprobar((int) $acceso['id']);

        $this->comoSuperAdmin();
        $this->postJson("/api/v1/accesos-soporte/{$acceso['id']}/activar")->assertOk();

        AccesoSoporte::whereKey((int) $acceso['id'])->update(['expira_en' => now()->subMinute()]);

        Artisan::call('soporte:expirar-accesos');

        $this->assertSame('expirado', AccesoSoporte::findOrFail((int) $acceso['id'])->estado->value);
        $this->assertEventoAuditoria('cancelar', 'soporte', 'acceso_soporte', (int) $acceso['id']);
        $this->getJson('/api/v1/clientes')->assertForbidden();
    }

    public function test_no_se_puede_solicitar_dos_veces_para_la_misma_empresa(): void
    {
        $this->solicitar();

        $this->comoSuperAdmin();
        $this->postJson('/api/v1/accesos-soporte', [
            'empresa_id' => $this->empresa->id,
            'motivo' => 'Otra revisión',
        ])->assertForbidden();
    }
}
