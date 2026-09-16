<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\CaptureAuditContext;
use App\Http\Middleware\EnsureAuthenticationMethodIsEnabled;
use App\Http\Middleware\EnsurePendingTwoFactorUserIsEnabled;
use App\Http\Middleware\EnsureUserIsEnabled;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectToSetupWhenUninitialized;
use App\Http\Middleware\RejectRequestsDuringApplicationDatabaseMigration;
use App\Http\Middleware\RequireInitialSetupAccess;
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
            Route::middleware([SubstituteBindings::class])
                ->group(base_path('routes/native-proxy.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(
            prepend: [
                AddSecurityHeaders::class,
                CaptureAuditContext::class,
                RejectRequestsDuringApplicationDatabaseMigration::class,
            ],
            append: [
                HandleAppearance::class,
                RedirectToSetupWhenUninitialized::class,
                EnsurePendingTwoFactorUserIsEnabled::class,
                EnsureUserIsEnabled::class,
                EnsureAuthenticationMethodIsEnabled::class,
                HandleInertiaRequests::class,
            ],
        );
        $middleware->alias([
            'application-database-migration' => RejectRequestsDuringApplicationDatabaseMigration::class,
            'initial-setup-access' => RequireInitialSetupAccess::class,
            'native-proxy-control' => VerifyNativeProxyControlRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
