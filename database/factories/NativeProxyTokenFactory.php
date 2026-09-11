<?php

namespace Database\Factories;

use App\Models\NativeProxyDeviceAuthorization;
use App\Models\NativeProxyLease;
use App\Models\NativeProxyToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NativeProxyToken>
 */
class NativeProxyTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lease_id' => NativeProxyLease::factory(),
            'device_authorization_id' => NativeProxyDeviceAuthorization::factory(),
            'token_hash' => fake()->unique()->sha256(),
            'scope' => 'native_tunnel',
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'last_used_at' => null,
            'revoked_at' => null,
            'revocation_reason' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['revoked_at' => null, 'expires_at' => now()->addMinutes(15)]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now(), 'revocation_reason' => 'Test revocation']);
    }
}
