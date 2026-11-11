<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada vez que se rehace el plan de cuotas de un crédito desembolsado (abono a capital o reprogramación) queda una
 * fila con la foto del plan anterior: con ella se reversa el pago que hizo el abono. `borrado_en` marca la fila
 * deshecha por esa reversa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reprogramaciones_credito', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->unsignedBigInteger('credito_id');
            $table->unsignedBigInteger('pago_id')->nullable();
            $table->string('tipo', 20);
            // Centavos, con DineroCast en el Model. Solo en el abono a capital.
            $table->bigInteger('valor_abono')->nullable();
            $table->string('motivo', 500)->nullable();
            $table->json('plan_anterior');
            $table->foreignId('creado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('creado_en')->nullable();
            $table->timestamp('ultima_actualizacion')->nullable();
            $table->softDeletes('borrado_en');

            $table->index(['empresa_id', 'credito_id']);
            $table->unique(['empresa_id', 'pago_id'], 'reprogramaciones_credito_pago_unico');

            $table->foreign(['empresa_id', 'credito_id'])->references(['empresa_id', 'id'])->on('creditos');
            $table->foreign(['empresa_id', 'pago_id'])->references(['empresa_id', 'id'])->on('pagos');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reprogramaciones_credito');
    }
};
