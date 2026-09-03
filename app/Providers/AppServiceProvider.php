<?php

namespace App\Providers;

use App\Events\NativeProxyCredentialsCreated;
use App\Events\NativeProxyLeaseRevoked;
use App\Services\ApplicationDatabaseMigrationFence;
use App\Services\ApplicationSettings;
use App\Services\NativeProxy\LeaseRevocationPublisher;
use App\Services\NotificationDispatcher;
use App\Support\ApplicationDatabaseBootstrap;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->isBuildingConfigurationCache()) {
            return;
        }

        $controlDatabase = ApplicationDatabaseBootstrap::resolve(
            base_path(),
            (string) config('database.control_metadata.mode'),
            (string) config('database.control_metadata.path'),
            (array) config('database.connections.control'),
            (string) config('app.key'),
            (string) config('app.cipher'),
        );

        config([
            'database.connections.control' => $controlDatabase['connection'],
            'database.control_metadata.mode' => $controlDatabase['mode'],
            'database.control_metadata.path' => $controlDatabase['path'],
            'database.control_metadata.configured' => $controlDatabase['configured'],
            'database.control_metadata.fingerprint' => $controlDatabase['fingerprint'],
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureApplicationSettings();
        $this->configureSocialiteProviders();
        $this->configureMailFromApplicationSettings();
        $this->configureNativeProxyRateLimiting();
        $this->configureNativeProxyRevocations();
        $this->configureApplicationDatabaseMigrationFence();
    }

    protected function configureApplicationSettings(): void
    {
        $settings = app(ApplicationSettings::class);

        config(['app.name' => $settings->appName()]);
        $settings->applyRuntimeConfiguration();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureSocialiteProviders(): void
    {
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('microsoft', MicrosoftProvider::class);
        });
    }

    protected function configureMailFromApplicationSettings(): void
    {
        Event::listen(function (NotificationSending $event): void {
            if ($event->channel === 'mail') {
                app(ApplicationSettings::class)->applyRuntimeConfiguration();
            }
        });
    }

    protected function configureNativeProxyRateLimiting(): void
    {
        RateLimiter::for('native-proxy-control', function (Request $request): Limit {
            return Limit::perMinute(600)->by($request->header('X-Crucible-Proxy-Id', $request->ip()));
        });

        RateLimiter::for('native-proxy-device-authorizations', function (Request $request): Limit {
            return Limit::perMinute((int) config('native_proxy.device_authorization_rate_limit'))
                ->by(implode(':', [
                    $request->header('X-Crucible-Proxy-Id', $request->ip()),
                    $request->input('lease_id', 'unknown'),
                ]));
        });

        RateLimiter::for('native-proxy-device-token', function (Request $request): Limit {
            return Limit::perMinute((int) config('native_proxy.device_token_rate_limit'))
                ->by($request->header('X-Crucible-Proxy-Id', $request->ip()))
                ->response(fn (Request $request, array $headers) => response()
                    ->json([
                        'error' => 'slow_down',
                        'interval' => max(5, (int) ($headers['Retry-After'] ?? 5)),
                    ], 429)
                    ->withHeaders($headers)
                    ->header('Cache-Control', 'no-store, private'));
        });
    }

    protected function configureNativeProxyRevocations(): void
    {
        Event::listen(NativeProxyLeaseRevoked::class, [LeaseRevocationPublisher::class, 'publish']);
        Event::listen(NativeProxyCredentialsCreated::class, function (NativeProxyCredentialsCreated $event): void {
            app(NotificationDispatcher::class)->nativeProxyCredentialsCreated($event->leaseId);
        });
        Event::listen(NativeProxyLeaseRevoked::class, function (NativeProxyLeaseRevoked $event): void {
            app(NotificationDispatcher::class)->nativeProxyLeasesRevoked($event->leaseIds, $event->reason);
        });
    }

    protected function configureApplicationDatabaseMigrationFence(): void
    {
        Event::listen(function (JobQueueing $event): void {
            if (app(ApplicationDatabaseMigrationFence::class)->isActive()) {
                throw new \RuntimeException('New queued work is blocked while the application database is being migrated.');
            }
        });
    }

    private function isBuildingConfigurationCache(): bool
    {
        if (! $this->app->runningInConsole()) {
            return false;
        }

        return in_array($_SERVER['argv'][1] ?? null, ['config:cache', 'optimize'], true);
    }
}
