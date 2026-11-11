<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accesos de soporte just-in-time del superadmin (Fase 4): el superadmin pide acceso a UNA empresa,
 * un administrador de esa empresa lo aprueba, el superadmin lo activa y solo entonces ve los datos
 * de esa empresa, solo en lectura y con vencimiento corto. Sin acceso activo, el superadmin no ve
 * datos de negocio de ninguna empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accesos_soporte', function (Blueprint $table) {
            $table->id();
            $table->foreignId('superadmin_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('admin_aprobador_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('motivo', 500);
            $table->string('estado', 20)->default('solicitado');
            $table->unsignedSmallInteger('duracion_minutos')->default(30);
            $table->timestamp('solicitado_en');
            $table->timestamp('aprobado_en')->nullable();
            $table->timestamp('activado_en')->nullable();
            $table->timestamp('expira_en')->nullable();
            $table->timestamp('revocado_en')->nullable();
            $table->timestamp('creado_en')->nullable();
            $table->timestamp('ultima_actualizacion')->nullable();
            $table->softDeletes('borrado_en');

            $table->index(['empresa_id', 'estado']);
            $table->index(['superadmin_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accesos_soporte');
    }
};
