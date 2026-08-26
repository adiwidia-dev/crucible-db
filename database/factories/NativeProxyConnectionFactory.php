<?php

namespace Database\Factories;

use App\Enums\DatabaseDriver;
use App\Enums\DatabaseTlsMode;
use App\Enums\NativeProxyConnectionStatus;
use App\Models\DatabaseConnection;
use App\Models\NativeProxyConnection;
use App\Models\NativeProxyLease;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NativeProxyConnection>
 */
class NativeProxyConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proxy_connection_id' => fake()->unique()->uuid(),
            'lease_id' => NativeProxyLease::factory(),
            'query_session_id' => QuerySession::factory(),
            'query_request_id' => QueryRequest::factory()->queryAccess(),
            'user_id' => User::factory(),
            'database_connection_id' => DatabaseConnection::factory(),
            'protocol' => DatabaseDriver::PostgreSql,
            'proxy_instance_id' => 'proxy-test',
            'client_application' => 'psql',
            'client_version' => '17.0',
            'cli_version' => '0.1.0',
            'operating_system' => 'darwin',
            'architecture' => 'arm64',
            'upstream_tls_mode' => DatabaseTlsMode::VerifyIdentity,
            'upstream_tls_verified' => true,
            'status' => NativeProxyConnectionStatus::Reserved,
            'reservation_expires_at' => now()->addSeconds(15),
            'connected_at' => now(),
            'authenticated_at' => null,
            'last_activity_at' => now(),
            'disconnected_at' => null,
            'disconnect_reason' => null,
            'bytes_received' => 0,
            'bytes_sent' => 0,
            'statement_count' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => NativeProxyConnectionStatus::Active, 'authenticated_at' => now(), 'reservation_expires_at' => null]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['status' => NativeProxyConnectionStatus::Closed, 'disconnected_at' => now(), 'disconnect_reason' => 'Test disconnect']);
    }
}
