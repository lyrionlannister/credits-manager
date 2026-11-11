<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alcance por empresa de las excepciones individuales de permiso.
 *
 * Antes, `usuario_permiso` solo tenía (usuario_id, permiso_id): el alcance por empresa era implícito
 * (cada fila de `usuarios` pertenece a una sola empresa), así que una excepción no podía distinguirse
 * por empresa ni diagnosticarse por empresa. Ahora la empresa queda explícita en la propia fila.
 *
 * Backfill: cada fila hereda la empresa de su usuario. Las filas de un usuario sin empresa (superadmin)
 * quedan con empresa_id null, como el propio usuario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuario_permiso', function (Blueprint $table) {
            $table->foreignId('empresa_id')->nullable()->after('usuario_id')
                ->constrained('empresas')->cascadeOnDelete();
        });

        DB::table('usuario_permiso')
            ->join('usuarios', 'usuarios.id', '=', 'usuario_permiso.usuario_id')
            ->select('usuario_permiso.id', 'usuarios.empresa_id')
            ->orderBy('usuario_permiso.id')
            ->chunkById(200, function ($filas) {
                foreach ($filas as $fila) {
                    DB::table('usuario_permiso')->where('id', $fila->id)->update(['empresa_id' => $fila->empresa_id]);
                }
            }, 'usuario_permiso.id');

        Schema::table('usuario_permiso', function (Blueprint $table) {
            $table->dropUnique(['usuario_id', 'permiso_id']);
            $table->unique(['usuario_id', 'empresa_id', 'permiso_id']);
        });
    }

    public function down(): void
    {
        Schema::table('usuario_permiso', function (Blueprint $table) {
            $table->dropUnique(['usuario_id', 'empresa_id', 'permiso_id']);
            $table->dropColumn('empresa_id');
            $table->unique(['usuario_id', 'permiso_id']);
        });
    }
};
