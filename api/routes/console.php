<?php

use App\Creditos\Services\CuotaService;
use App\Monitoreo\Constants\TareaProgramadaConstants;
use App\Monitoreo\Services\ProgramacionTareasService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Hora y pausa de cada tarea (salvo `respaldos:ejecutar`, que no se pausa) se leen de la tabla
 * `tareas_programadas`, con las horas de hoy como respaldo si la base no responde. `schedule:work`
 * lanza un `schedule:run` nuevo cada minuto (`ScheduleWorkCommand::work()`), así que un cambio en la
 * tabla aplica en menos de un minuto sin reiniciar nada.
 */
$servicioProgramacion = app(ProgramacionTareasService::class);

$horaDe = fn (string $clave) => substr(
    $servicioProgramacion->horariosVigentes()[$clave]['hora'] ?? TareaProgramadaConstants::HORAS_POR_DEFECTO[$clave],
    0,
    5,
);
$activaDe = fn (string $clave) => $servicioProgramacion->horariosVigentes()[$clave]['activa'] ?? true;

/**
 * Marca la mora de todas las empresas una vez al día, sin sesión. Las llamadas a
 * `CuotaService::actualizarVencidas` en las lecturas de Créditos/Cartera/Calendario/Dashboard
 * se mantienen como respaldo por si el servicio `scheduler` estuviera detenido.
 */
Schedule::call(fn () => app(CuotaService::class)->actualizarVencidas())
    ->dailyAt($horaDe('cuotas:actualizar-vencidas'))
    ->when(fn () => $activaDe('cuotas:actualizar-vencidas'))
    ->name('cuotas:actualizar-vencidas')
    ->withoutOverlapping(10);

/** Expira los accesos de soporte just-in-time vencidos (el servicio también expira al leer). */
Schedule::command('soporte:expirar-accesos')
    ->everyFifteenMinutes()
    ->name('soporte:expirar-accesos')
    ->withoutOverlapping(5);

Schedule::command('exportaciones:limpiar')
    ->dailyAt($horaDe('exportaciones:limpiar'))
    ->when(fn () => $activaDe('exportaciones:limpiar'))
    ->name('exportaciones:limpiar')
    ->withoutOverlapping(30);

/** Cierra las metas vencidas y genera alertas, empresa por empresa (cada una con su `empresa_id` explícito). */
Schedule::command('metas:evaluar')
    ->dailyAt($horaDe('metas:evaluar'))
    ->when(fn () => $activaDe('metas:evaluar'))
    ->name('metas:evaluar')
    ->withoutOverlapping(30);

/** Genera las alertas del calendario tributario por vencer, empresa por empresa (cada una con su `empresa_id` explícito). */
Schedule::command('obligaciones_tributarias:evaluar-alertas')
    ->dailyAt($horaDe('obligaciones_tributarias:evaluar-alertas'))
    ->when(fn () => $activaDe('obligaciones_tributarias:evaluar-alertas'))
    ->name('obligaciones_tributarias:evaluar-alertas')
    ->withoutOverlapping(30);

Schedule::command('importaciones:limpiar')
    ->dailyAt($horaDe('importaciones:limpiar'))
    ->when(fn () => $activaDe('importaciones:limpiar'))
    ->name('importaciones:limpiar')
    ->withoutOverlapping(30);

/** Devenga el 1 de cada mes lo vencido hasta hoy; si un mes falla, el siguiente lo recupera (la consulta es acumulativa, no solo del mes). */
Schedule::command('contabilidad:devengar-intereses')
    ->monthlyOn(1, $horaDe('contabilidad:devengar-intereses'))
    ->when(fn () => $activaDe('contabilidad:devengar-intereses'))
    ->name('contabilidad:devengar-intereses')
    ->withoutOverlapping(60);

Schedule::command('permisos:vencer-excepciones')
    ->dailyAt($horaDe('permisos:vencer-excepciones'))
    ->when(fn () => $activaDe('permisos:vencer-excepciones'))
    ->name('permisos:vencer-excepciones')
    ->withoutOverlapping(10);

/**
 * Respaldo diario de base y archivos (los domingos y el día 1 también se copia a semanal y mensual).
 * Un fallo queda en el log del scheduler; el respaldo anterior sigue disponible. No admite pausa desde
 * la UI (`TareaProgramadaConstants::CLAVE_NO_PAUSABLE`): sin `when()`, solo su hora es editable.
 */
Schedule::command('respaldos:ejecutar')
    ->dailyAt($horaDe('respaldos:ejecutar'))
    ->name('respaldos:ejecutar')
    ->withoutOverlapping(120)
    ->onFailure(fn () => Log::error('El respaldo automático falló; revisar la salida del servicio scheduler.'));
