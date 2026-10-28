<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Si el logo subido es SVG (se ve mejor en la web), aquí queda la versión PNG rasterizada solo para los PDF. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('logo_pdf_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('logo_pdf_path');
        });
    }
};
