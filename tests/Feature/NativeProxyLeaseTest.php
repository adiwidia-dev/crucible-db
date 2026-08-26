<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Enums\DatabaseDriver;
use App\Enums\NativeProxyConnectionStatus;
use App\Enums\NativeProxyDeviceAuthorizationStatus;
use App\Enums\NativeProxyLeaseStatus;
use App\Models\NativeProxyAuthAttempt;
use App\Models\NativeProxyConnection;
use App\Models\NativeProxyDeviceAuthorization;
use App\Models\NativeProxyLease;
use App\Models\NativeProxyToken;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NativeProxyLeaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_proxy_lease_uses_a_ulid_and_hides_encrypted_protocol_secrets(): void
    {
        $lease = NativeProxyLease::factory()->create([
            'protocol' => DatabaseDriver::PostgreSql,
            'access_mode' => AccessMode::Read,
            'status' => NativeProxyLeaseStatus::Active,
            'synthetic_password_hash' => 'hashed password',
            'protocol_auth_secret' => 'protocol secret',
        ]);

        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $lease->id);
        $this->assertSame(NativeProxyLeaseStatus::Active, $lease->status);
        $this->assertSame(AccessMode::Read, $lease->access_mode);
        $this->assertSame('protocol secret', $lease->protocol_auth_secret);
        $this->assertArrayNotHasKey('synthetic_password_hash', $lease->toArray());
        $this->assertArrayNotHasKey('protocol_auth_secret', $lease->toArray());
    }

    public function test_native_proxy_models_link_the_lease_to_tokens_devices_attempts_and_connections(): void
    {
        $lease = NativeProxyLease::factory()->create();
        $deviceAuthorization = NativeProxyDeviceAuthorization::factory()->for($lease, 'lease')->create([
            'status' => NativeProxyDeviceAuthorizationStatus::Approved,
        ]);
        $token = NativeProxyToken::factory()
            ->for($lease, 'lease')
            ->for($deviceAuthorization, 'deviceAuthorization')
            ->create();
        $authAttempt = NativeProxyAuthAttempt::factory()
            ->for($lease, 'lease')
            ->for($deviceAuthorization, 'deviceAuthorization')
            ->for($token, 'token')
            ->create();
        $connection = NativeProxyConnection::factory()->for($lease, 'lease')->create([
            'status' => NativeProxyConnectionStatus::Active,
        ]);

        $this->assertTrue($lease->deviceAuthorizations->contains($deviceAuthorization));
        $this->assertTrue($lease->tokens->contains($token));
        $this->assertTrue($lease->authAttempts->contains($authAttempt));
        $this->assertTrue($lease->connections->contains($connection));
        $this->assertSame($lease->id, $connection->lease->id);
        $this->assertSame(NativeProxyConnectionStatus::Active, $connection->status);
    }

    public function test_query_session_has_only_one_native_proxy_lease_and_proxy_connection_ids_are_unique(): void
    {
        $lease = NativeProxyLease::factory()->create();

        $this->expectException(QueryException::class);

        NativeProxyLease::factory()->create([
            'query_session_id' => $lease->query_session_id,
        ]);
    }

    public function test_proxy_connection_ids_are_unique(): void
    {
        $connection = NativeProxyConnection::factory()->create();

        $this->expectException(QueryException::class);

        NativeProxyConnection::factory()->create([
            'proxy_connection_id' => $connection->proxy_connection_id,
        ]);
    }

    public function test_active_native_proxy_scopes_exclude_revoked_or_closed_control_records(): void
    {
        $activeLease = NativeProxyLease::factory()->active()->create();
        NativeProxyLease::factory()->revoked()->create();
        $activeDevice = NativeProxyDeviceAuthorization::factory()->approved()->create([
            'lease_id' => $activeLease->id,
        ]);
        NativeProxyDeviceAuthorization::factory()->expired()->create([
            'lease_id' => $activeLease->id,
        ]);
        NativeProxyToken::factory()->active()->for($activeLease, 'lease')->for($activeDevice, 'deviceAuthorization')->create();
        NativeProxyToken::factory()->revoked()->for($activeLease, 'lease')->for($activeDevice, 'deviceAuthorization')->create();
        NativeProxyConnection::factory()->active()->for($activeLease, 'lease')->create();
        NativeProxyConnection::factory()->closed()->for($activeLease, 'lease')->create();

        $this->assertCount(1, NativeProxyLease::query()->active()->get());
        $this->assertCount(1, NativeProxyDeviceAuthorization::query()->active()->get());
        $this->assertCount(1, NativeProxyToken::query()->active()->get());
        $this->assertCount(1, NativeProxyConnection::query()->active()->get());
    }
}
