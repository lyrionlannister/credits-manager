<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DDA pacta la tasa como efectiva anual y deriva la mensual. Se guarda la EA
 * y la mensual sale de ella sin redondear; los créditos anteriores quedan con la EA nula y siguen con su mensual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->decimal('tasa_interes', 9, 6)->change();
            $table->decimal('tasa_efectiva_anual', 7, 4)->nullable()->after('tasa_interes');
        });
    }

    public function down(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->dropColumn('tasa_efectiva_anual');
            $table->decimal('tasa_interes', 6, 3)->change();
        });
    }
};
