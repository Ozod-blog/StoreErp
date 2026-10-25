<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\BroadcastErpMutations;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withBroadcasting(__DIR__ . '/../routes/channels.php', [
        'prefix' => 'api/v1',
        'middleware' => ['api', 'auth:sanctum'],
    ])
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi();
        $middleware->api(append: [BroadcastErpMutations::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API har doim JSON qaytarsin (login sahifasiga redirect bo'lmasin).
        $exceptions->shouldRenderJsonWhen(fn(Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Avtorizatsiyadan o‘tilmagan. Qayta kiring.'], 401);
            }
        });

        $forbidden = function (Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Bu amalni bajarishga ruxsatingiz yo‘q.'], 403);
            }
        };
        $exceptions->render(fn(AccessDeniedHttpException $e, Request $request) => $forbidden($request));
        $exceptions->render(fn(AuthorizationException $e, Request $request) => $forbidden($request));
    })->create();
