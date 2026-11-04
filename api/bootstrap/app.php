<?php

use App\Auth\Middleware\DebeCambiarClave;
use App\Auth\Middleware\EsAdmin;
use App\Auth\Middleware\EsSuperAdmin;
use App\Auth\Middleware\TieneAlgunPermiso;
use App\Auth\Middleware\TienePermiso;
use App\Auth\Middleware\TienePermisoOSuperAdmin;
use App\Exportacion\Middleware\PuedeExportar;
use App\Metas\Middleware\AccesoMetas;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->alias([
            'debe_cambiar_clave' => DebeCambiarClave::class,
            'admin' => EsAdmin::class,
            'superadmin' => EsSuperAdmin::class,
            'permiso' => TienePermiso::class,
            'algun_permiso' => TieneAlgunPermiso::class,
            'permiso_o_superadmin' => TienePermisoOSuperAdmin::class,
            'acceso_metas' => AccesoMetas::class,
            'exportacion' => PuedeExportar::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface
                || $e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof AuthorizationException) {
                return null;
            }

            return response()->json([
                'message' => 'Ha ocurrido un error inesperado.',
            ], 500);
        });
    })
    ->create()
    ->useAppPath(dirname(__DIR__).'/src');
