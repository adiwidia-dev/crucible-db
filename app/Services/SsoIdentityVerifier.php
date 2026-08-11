<?php

namespace App\Services;

use App\Enums\AuthProviderType;
use App\Models\AuthProvider;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class SsoIdentityVerifier
{
    public function hasTrustedEmail(AuthProvider $authProvider, SocialiteUser $providerUser): bool
    {
        $email = Str::lower(trim((string) $providerUser->getEmail()));

        if ($email === '') {
            return false;
        }

        $rawUser = $this->rawUser($providerUser);

        return match ($authProvider->provider) {
            AuthProviderType::Google => ($rawUser['email_verified'] ?? $rawUser['verified_email'] ?? false) === true,
            AuthProviderType::GitHub => in_array('user:email', $authProvider->effectiveScopes(), true),
            AuthProviderType::Microsoft => Str::lower(trim((string) ($rawUser['userPrincipalName'] ?? ''))) === $email,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function rawUser(SocialiteUser $providerUser): array
    {
        if (! method_exists($providerUser, 'getRaw')) {
            return [];
        }

        $rawUser = $providerUser->getRaw();

        return is_array($rawUser) ? $rawUser : [];
    }
}
