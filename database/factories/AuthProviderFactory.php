<?php

namespace Database\Factories;

use App\Enums\AuthProviderType;
use App\Models\AuthProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthProvider>
 */
class AuthProviderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $provider = fake()->randomElement(AuthProviderType::cases());

        return [
            'provider' => $provider,
            'name' => $provider->label(),
            'client_id' => fake()->uuid(),
            'client_secret' => fake()->sha256(),
            'scopes' => $provider->defaultScopes(),
            'allowed_domains' => null,
            'tenant' => $provider === AuthProviderType::Microsoft ? 'common' : null,
            'is_enabled' => true,
        ];
    }

    public function google(): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => AuthProviderType::Google,
            'name' => 'Google',
            'scopes' => AuthProviderType::Google->defaultScopes(),
            'tenant' => null,
        ]);
    }

    public function github(): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => AuthProviderType::GitHub,
            'name' => 'GitHub',
            'scopes' => AuthProviderType::GitHub->defaultScopes(),
            'tenant' => null,
        ]);
    }

    public function microsoft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => AuthProviderType::Microsoft,
            'name' => 'Microsoft',
            'scopes' => AuthProviderType::Microsoft->defaultScopes(),
            'tenant' => 'common',
        ]);
    }
}
