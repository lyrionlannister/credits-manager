<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Excepciones individuales temporales: toda excepción lleva motivo, responsable y vencimiento.
 *
 * - `motivo`: por qué se concede o se revoca este permiso a este usuario en esta empresa.
 * - `responsable_id`: quien concedió o revocó (el usuario autenticado que hizo el cambio).
 * - `vence_en`: por defecto 30 días desde su creación, máximo 90. Al vencer, la excepción deja
 *   de aplicar (la revoca el trabajo `permisos:vencer-excepciones`); una revocación vencida
 *   devuelve el mando al rol.
 *
 * Backfill: las excepciones existentes quedan con vence_en = última actualización + 30 días,
 * para que la auditoría de despliegue las revise antes de que venzan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuario_permiso', function (Blueprint $table) {
            $table->string('motivo', 500)->nullable()->after('concedido');
            $table->foreignId('responsable_id')->nullable()->after('motivo')
                ->constrained('usuarios')->nullOnDelete();
            $table->timestamp('vence_en')->nullable()->after('responsable_id');
        });

        // En PHP (no en SQL crudo) para que funcione igual en MySQL y en SQLite.
        DB::table('usuario_permiso')
            ->whereNull('vence_en')
            ->select(['id', 'creado_en', 'ultima_actualizacion'])
            ->orderBy('id')
            ->chunkById(200, function ($filas) {
                foreach ($filas as $fila) {
                    $base = $fila->ultima_actualizacion ?? $fila->creado_en ?? now();
                    DB::table('usuario_permiso')->where('id', $fila->id)->update([
                        'vence_en' => Carbon::parse($base)->addDays(30),
                    ]);
                }
            }, 'usuario_permiso.id');
    }

    public function down(): void
    {
        Schema::table('usuario_permiso', function (Blueprint $table) {
            $table->dropColumn(['motivo', 'responsable_id', 'vence_en']);
        });
    }
};
