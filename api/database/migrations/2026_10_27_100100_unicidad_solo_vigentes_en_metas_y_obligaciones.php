<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las unicidades de negocio de estas tablas contaban también las filas borradas, así que eliminar una meta, una
 * obligación o un tipo bloqueaba volver a crearlo. `vigente` vale 1 solo en filas sin borrar (NULL en las borradas,
 * que el índice único ignora), mismo patrón que `usuarios.clave_superadmin`. El índice nuevo se crea antes de soltar
 * el viejo para que la FK de `empresa_id` nunca se quede sin índice.
 */
return new class extends Migration
{
    /** tabla => [columnas de negocio, índice viejo, índice nuevo] */
    private const TABLAS = [
        'metas' => [['empresa_id', 'periodo', 'tipo'], 'metas_empresa_id_periodo_tipo_unique', 'metas_vigente_periodo_tipo_unique'],
        'obligaciones_tributarias' => [['empresa_id', 'tipo_obligacion_id', 'periodo'], 'obl_trib_empresa_tipo_periodo_unique', 'obl_trib_vigente_tipo_periodo_unique'],
        'tipos_obligacion_tributaria' => [['empresa_id', 'nombre'], 'tipos_obligacion_tributaria_empresa_id_nombre_unique', 'tipos_obl_trib_vigente_nombre_unique'],
    ];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla => [$columnas, $indiceViejo, $indiceNuevo]) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->unsignedTinyInteger('vigente')->virtualAs('case when borrado_en is null then 1 end')->nullable();
            });

            Schema::table($tabla, function (Blueprint $table) use ($columnas, $indiceViejo, $indiceNuevo) {
                $table->unique([...$columnas, 'vigente'], $indiceNuevo);
                $table->dropUnique($indiceViejo);
            });
        }
    }

    /** Falla si ya hay filas borradas que repiten una vigente: en ese caso no hay forma de volver a la unicidad anterior. */
    public function down(): void
    {
        foreach (self::TABLAS as $tabla => [$columnas, $indiceViejo, $indiceNuevo]) {
            Schema::table($tabla, function (Blueprint $table) use ($columnas, $indiceViejo, $indiceNuevo) {
                $table->unique($columnas, $indiceViejo);
                $table->dropUnique($indiceNuevo);
            });

            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn('vigente');
            });
        }
    }
};
