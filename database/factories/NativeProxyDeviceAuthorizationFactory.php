<?php

namespace Database\Factories;

use App\Enums\NativeProxyDeviceAuthorizationStatus;
use App\Models\NativeProxyDeviceAuthorization;
use App\Models\NativeProxyLease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NativeProxyDeviceAuthorization>
 */
class NativeProxyDeviceAuthorizationFactory extends Factory
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
            'device_code_hash' => fake()->unique()->sha256(),
            'user_code_hash' => fake()->unique()->sha256(),
            'cli_version' => '0.1.0',
            'operating_system' => 'darwin',
            'architecture' => 'arm64',
            'device_label' => 'Test workstation',
            'polling_interval_seconds' => 5,
            'poll_count' => 0,
            'status' => NativeProxyDeviceAuthorizationStatus::Pending,
            'expires_at' => now()->addMinutes(5),
            'last_polled_at' => null,
            'approved_at' => null,
            'authorized_by_id' => null,
            'denied_at' => null,
            'consumed_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => NativeProxyDeviceAuthorizationStatus::Approved, 'approved_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['status' => NativeProxyDeviceAuthorizationStatus::Expired, 'expires_at' => now()->subSecond()]);
    }
}
