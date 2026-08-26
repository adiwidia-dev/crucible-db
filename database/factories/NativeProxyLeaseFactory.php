<?php

namespace Database\Factories;

use App\Enums\AccessMode;
use App\Enums\DatabaseDriver;
use App\Enums\NativeProxyLeaseStatus;
use App\Models\DatabaseConnection;
use App\Models\NativeProxyLease;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NativeProxyLease>
 */
class NativeProxyLeaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'query_session_id' => QuerySession::factory(),
            'query_request_id' => QueryRequest::factory()->queryAccess(),
            'user_id' => User::factory(),
            'database_connection_id' => DatabaseConnection::factory(),
            'protocol' => DatabaseDriver::PostgreSql,
            'access_mode' => AccessMode::Read,
            'synthetic_username' => 'crucible_'.fake()->unique()->uuid(),
            'synthetic_password_hash' => fake()->sha256(),
            'protocol_auth_secret' => fake()->sha256(),
            'credential_version' => 1,
            'status' => NativeProxyLeaseStatus::PendingCredentials,
            'max_concurrent_connections' => 3,
            'credentials_revealed_at' => null,
            'activated_at' => null,
            'expires_at' => now()->addHour(),
            'last_used_at' => null,
            'revoked_at' => null,
            'revocation_reason' => null,
            'revoked_by_id' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => NativeProxyLeaseStatus::Active, 'activated_at' => now()]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['status' => NativeProxyLeaseStatus::Revoked, 'revoked_at' => now()]);
    }
}
