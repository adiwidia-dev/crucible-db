<?php

namespace App\Services;

use App\Models\AuthProvider;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteManager;
use LogicException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SsoProviderConfigurator
{
    public function driver(AuthProvider $authProvider): Provider
    {
        $provider = $authProvider->provider->value;

        $this->forgetDrivers(app(Factory::class));

        config([
            "services.{$provider}" => array_filter([
                'client_id' => $authProvider->client_id,
                'client_secret' => $authProvider->client_secret,
                'redirect' => $authProvider->callbackUrl(),
                'tenant' => $authProvider->tenant,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);

        $driver = Socialite::driver($provider);

        return $driver;
    }

    public function redirect(AuthProvider $authProvider): RedirectResponse
    {
        $driver = $this->driver($authProvider);
        $configureScopes = [$driver, 'scopes'];

        if (! is_callable($configureScopes)) {
            throw new LogicException("The {$authProvider->provider->value} SSO driver does not support OAuth scopes.");
        }

        /** @var Provider $scopedDriver */
        $scopedDriver = call_user_func($configureScopes, $authProvider->effectiveScopes());

        return $scopedDriver->redirect();
    }

    private function forgetDrivers(Factory $socialite): void
    {
        if ($socialite instanceof SocialiteManager) {
            $socialite->forgetDrivers();
        }
    }
}
