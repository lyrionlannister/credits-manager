<?php

namespace App\Providers;

use App\Auditoria\Commands\EscucharAuditoriaCommand;
use App\Auditoria\Commands\EstadoAuditoriaCommand;
use App\Contabilidad\Commands\DevengarInteresesCommand;
use App\Empresas\Commands\RasterizarLogosPendientesCommand;
use App\Exportacion\Commands\LimpiarExportacionesCommand;
use App\Importacion\Commands\LimpiarImportacionesCommand;
use App\Metas\Commands\EvaluarMetasCommand;
use App\Monitoreo\Commands\PublicarMonitoreoCommand;
use App\Monitoreo\Listeners\LatidoScheduler;
use App\Monitoreo\Listeners\LatidoTrabajadores;
use App\Monitoreo\Listeners\RegistroEjecucionTarea;
use App\Monitoreo\Services\RegistroLatidos;
use App\ObligacionesTributarias\Commands\EvaluarAlertasObligacionesCommand;
use App\Redis\Contracts\RedisClienteInterface;
use App\Redis\Services\RedisCliente;
use App\Respaldos\Commands\EjecutarRespaldoCommand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RedisClienteInterface::class, RedisCliente::class);
        // Una instancia por proceso: recuerda cuándo escribió el último latido para no escribir en cada vuelta del worker.
        $this->app->singleton(RegistroLatidos::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->commands([EscucharAuditoriaCommand::class, EstadoAuditoriaCommand::class, LimpiarExportacionesCommand::class, LimpiarImportacionesCommand::class, EvaluarMetasCommand::class, EjecutarRespaldoCommand::class, EvaluarAlertasObligacionesCommand::class, PublicarMonitoreoCommand::class, DevengarInteresesCommand::class, RasterizarLogosPendientesCommand::class]);

        Event::listen(Looping::class, [LatidoTrabajadores::class, 'enEspera']);
        Event::listen(JobProcessing::class, [LatidoTrabajadores::class, 'alEmpezar']);
        Event::listen([JobProcessed::class, JobFailed::class], [LatidoTrabajadores::class, 'alTerminar']);
        Event::listen(CommandStarting::class, LatidoScheduler::class);
        Event::listen(ScheduledTaskFinished::class, [RegistroEjecucionTarea::class, 'alTerminar']);
        Event::listen([ScheduledTaskSkipped::class, ScheduledTaskFailed::class], [RegistroEjecucionTarea::class, 'alFallar']);

        RateLimiter::for('login', function (Request $peticion) {
            $usuario = Str::lower((string) $peticion->input('usuario'));

            // El tope por IP acota las filas que un cliente puede provocar en la bitácora (solo inserción) probando usuarios distintos.
            return [
                Limit::perMinute(5)->by($usuario !== '' ? $usuario : $peticion->ip()),
                Limit::perMinute(30)->by('ip:'.$peticion->ip()),
            ];
        });
    }
}
