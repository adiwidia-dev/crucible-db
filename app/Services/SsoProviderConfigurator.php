<?php

namespace App\Services;

use App\Models\AuthProvider;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteManager;

class SsoProviderConfigurator
{
    public function driver(AuthProvider $authProvider): Provider
    {
        $provider = $authProvider->provider->value;

        /** @var SocialiteManager $socialite */
        $socialite = app(Factory::class);
        if (method_exists($socialite, 'forgetDrivers')) {
            $socialite->forgetDrivers();
        }

        config([
            "services.{$provider}" => array_filter([
                'client_id' => $authProvider->client_id,
                'client_secret' => $authProvider->client_secret,
                'redirect' => $authProvider->callbackUrl(),
                'tenant' => $authProvider->tenant,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);

        return Socialite::driver($provider);
    }
}
