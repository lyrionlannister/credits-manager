<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La UVT puede cambiar más de una vez dentro del mismo año (corrección de un valor mal cargado, o una
 * regulación que se ajusta a mitad de año). `anio` no distingue esos casos: pasa a ser un rango de vigencia
 * (`fecha_desde`/`fecha_hasta`), mismo patrón que `parametrizaciones_tipo_gasto` y `tarifas_reteica_municipio`.
 * El valor vigente es el de `fecha_hasta` nula; los gastos ya causados siguen señalando al valor de su propio
 * rango aunque ya no sea el vigente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valores_uvt', function (Blueprint $table) {
            $table->date('fecha_desde')->nullable()->after('anio');
            $table->date('fecha_hasta')->nullable()->after('fecha_desde');
        });

        DB::statement("update valores_uvt set fecha_desde = concat(anio, '-01-01')");

        // Índice nuevo en una migración de esquema aparte: Laravel siempre compila los `drop` de un mismo
        // `Schema::table()` antes que los `add`, así que el viejo no se puede soltar en el mismo paso sin
        // dejar la FK de empresa_id sin índice un instante. Con el nuevo ya creado, el orden no importa.
        Schema::table('valores_uvt', function (Blueprint $table) {
            $table->index(['empresa_id', 'fecha_desde'], 'valores_uvt_vigencia_index');
        });

        Schema::table('valores_uvt', function (Blueprint $table) {
            $table->date('fecha_desde')->nullable(false)->change();
            $table->dropUnique(['empresa_id', 'anio']);
            $table->dropColumn('anio');
        });
    }

    public function down(): void
    {
        Schema::table('valores_uvt', function (Blueprint $table) {
            $table->unsignedSmallInteger('anio')->nullable()->after('empresa_id');
        });

        DB::statement('update valores_uvt set anio = year(fecha_desde)');

        Schema::table('valores_uvt', function (Blueprint $table) {
            $table->unsignedSmallInteger('anio')->nullable(false)->change();
            $table->unique(['empresa_id', 'anio']);
        });

        Schema::table('valores_uvt', function (Blueprint $table) {
            $table->dropIndex('valores_uvt_vigencia_index');
            $table->dropColumn(['fecha_desde', 'fecha_hasta']);
        });
    }
};
