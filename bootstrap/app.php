<?php

use App\Http\Middleware\EnsureAuthenticationMethodIsEnabled;
use App\Http\Middleware\EnsureUserIsEnabled;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectToSetupWhenUninitialized;
use App\Http\Middleware\VerifyNativeProxyControlRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware(['throttle:native-proxy-control', SubstituteBindings::class])
                ->group(base_path('routes/native-proxy.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            RedirectToSetupWhenUninitialized::class,
            EnsureUserIsEnabled::class,
            EnsureAuthenticationMethodIsEnabled::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'native-proxy-control' => VerifyNativeProxyControlRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
