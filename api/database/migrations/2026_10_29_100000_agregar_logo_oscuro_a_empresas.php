<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Logo alterno para la UI en modo oscuro; el PDF siempre usa logo_path/logo_pdf_path (se imprime sobre fondo claro). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('logo_dark_path')->nullable()->after('logo_pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('logo_dark_path');
        });
    }
};
