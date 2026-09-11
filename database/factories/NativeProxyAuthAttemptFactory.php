<?php

namespace Database\Factories;

use App\Enums\DatabaseDriver;
use App\Models\NativeProxyAuthAttempt;
use App\Models\NativeProxyDeviceAuthorization;
use App\Models\NativeProxyLease;
use App\Models\NativeProxyToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NativeProxyAuthAttempt>
 */
class NativeProxyAuthAttemptFactory extends Factory
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
            'token_id' => NativeProxyToken::factory(),
            'device_authorization_id' => NativeProxyDeviceAuthorization::factory(),
            'proxy_instance_id' => 'proxy-test',
            'protocol' => DatabaseDriver::PostgreSql,
            'credential_version' => 1,
            'proxy_connection_id' => fake()->unique()->uuid(),
            'status' => 'pending',
            'expires_at' => now()->addSeconds(15),
            'consumed_at' => null,
        ];
    }
}
