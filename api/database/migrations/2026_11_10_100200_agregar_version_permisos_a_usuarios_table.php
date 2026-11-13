<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versión de permisos del usuario (Fase 3): cambia cada vez que cambian los permisos de su rol
 * o sus excepciones individuales. `GET /me` la devuelve y el cliente la usa para detectar que
 * sus permisos cambiaron; la Fase 9 usa la misma versión para invalidar el caché de Redis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->unsignedInteger('version_permisos')->default(1)->after('rol_id');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('version_permisos');
        });
    }
};
