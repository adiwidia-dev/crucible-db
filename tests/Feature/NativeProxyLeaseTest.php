<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Enums\AccessTransport;
use App\Enums\DatabaseDriver;
use App\Enums\NativeProxyConnectionStatus;
use App\Enums\NativeProxyDeviceAuthorizationStatus;
use App\Enums\NativeProxyLeaseStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Models\AuditLog;
use App\Models\DatabaseConnection;
use App\Models\NativeProxyAuthAttempt;
use App\Models\NativeProxyConnection;
use App\Models\NativeProxyDeviceAuthorization;
use App\Models\NativeProxyLease;
use App\Models\NativeProxyToken;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\Role;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use App\Services\ApplicationSettings;
use App\Services\NativeProxy\ConnectionAdmission;
use App\Services\NativeProxy\LeaseWorkflow;
use App\Services\NativeProxy\TunnelAuthorizationData;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    public function test_owner_can_create_native_proxy_credentials_once_and_the_plaintext_is_not_persisted(): void
    {
        [$owner, $session] = $this->nativeSession();

        $response = $this->actingAs($owner)
            ->postJson(route('query-sessions.native-proxy.credentials.store', $session), [], [
                'Idempotency-Key' => 'lease-create-key-1234',
            ]);

        $response
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['lease_id', 'username', 'password', 'credential_version', 'expires_at']);

        $password = $response->json('password');
        $lease = NativeProxyLease::query()->firstOrFail();
        $storedLease = DB::table('native_proxy_leases')->where('id', $lease->id)->first();

        $this->assertIsString($password);
        $this->assertSame(32, strlen(base64_decode(strtr($password, '-_', '+/'), true)));
        $this->assertMatchesRegularExpression('/^crucible_[a-f0-9]{32}$/', $lease->synthetic_username);
        $this->assertTrue(Hash::check($password, $lease->synthetic_password_hash));
        $this->assertNotSame($password, $storedLease->protocol_auth_secret);
        $this->assertStringNotContainsString($password, $storedLease->protocol_auth_secret);
        $this->assertSame(NativeProxyLeaseStatus::Active, $lease->status);
        $this->assertTrue(AuditLog::query()->where('action', 'native_proxy.lease_created')->where('auditable_id', $lease->id)->exists());
        $response->assertJsonMissingPath('synthetic_password_hash')->assertJsonMissingPath('protocol_auth_secret');

        $this->actingAs($owner)
            ->postJson(route('query-sessions.native-proxy.credentials.store', $session), [], [
                'Idempotency-Key' => 'lease-create-key-1234',
            ])
            ->assertConflict()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('code', 'credentials_already_created');

        $this->assertSame(1, NativeProxyLease::query()->count());
    }

    public function test_new_native_proxy_credentials_use_the_configured_username_prefix(): void
    {
        app(ApplicationSettings::class)->put([
            ApplicationSettings::NativeProxyUsernamePrefix => 'operations-',
        ]);
        [$owner, $session] = $this->nativeSession();

        $credential = app(LeaseWorkflow::class)->create($session, $owner, 'custom-prefix-key-1234');

        $this->assertMatchesRegularExpression('/^operations-[a-f0-9]{32}$/', $credential->username);
    }

    public function test_owner_can_rotate_and_revoke_native_proxy_credentials_without_exposing_the_previous_secret(): void
    {
        [$owner, $session] = $this->nativeSession();
        $workflow = app(LeaseWorkflow::class);
        $first = $workflow->create($session, $owner, 'lease-create-key-5678');
        $lease = $first->lease;
        $originalUsername = $first->username;
        $deviceAuthorization = NativeProxyDeviceAuthorization::factory()
            ->for($lease, 'lease')
            ->create([
                'status' => NativeProxyDeviceAuthorizationStatus::Consumed,
                'approved_at' => now(),
                'consumed_at' => now(),
            ]);
        $bearerToken = 'approved-cli-token';
        $token = NativeProxyToken::factory()
            ->for($lease, 'lease')
            ->for($deviceAuthorization, 'deviceAuthorization')
            ->create(['token_hash' => hash('sha256', $bearerToken)]);
        $authAttempt = NativeProxyAuthAttempt::factory()
            ->for($lease, 'lease')
            ->for($deviceAuthorization, 'deviceAuthorization')
            ->for($token, 'token')
            ->create();
        $connection = NativeProxyConnection::factory()->for($lease, 'lease')->create([
            'status' => NativeProxyConnectionStatus::Active,
        ]);
        app(ApplicationSettings::class)->put([
            ApplicationSettings::NativeProxyUsernamePrefix => 'changed_',
        ]);

        $response = $this->actingAs($owner)
            ->postJson(route('native-proxy-leases.credentials.rotate', $lease));

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('credential_version', 2);

        $lease->refresh();
        $this->assertSame(2, $lease->credential_version);
        $this->assertSame($originalUsername, $response->json('username'));
        $this->assertSame($originalUsername, $lease->synthetic_username);
        $this->assertNotSame($first->password, $response->json('password'));
        $this->assertFalse(Hash::check($first->password, $lease->synthetic_password_hash));
        $this->assertNull($token->refresh()->revoked_at);
        $this->assertSame('expired', $authAttempt->refresh()->status);
        $this->assertSame(NativeProxyConnectionStatus::Revoked, $connection->refresh()->status);
        $this->assertFalse(app(ConnectionAdmission::class)->heartbeat($connection->fresh())->continueConnection);

        $authorization = app(ConnectionAdmission::class)->authorizeTunnel(new TunnelAuthorizationData(
            $lease->id,
            $deviceAuthorization->id,
            'proxy-connection-after-rotation',
            'proxy-01',
            DatabaseDriver::PostgreSql,
            hash('sha256', $bearerToken),
        ));

        $this->assertSame(2, $authorization->credentialVersion);
        $this->assertSame($response->json('password'), $authorization->protocolAuthenticationSecret);

        $this->actingAs($owner)
            ->postJson(route('native-proxy-leases.revoke', $lease), ['reason' => 'Work completed.'])
            ->assertNoContent()
            ->assertHeader('Cache-Control', 'no-store, private');

        $lease->refresh();
        $this->assertSame(NativeProxyLeaseStatus::Revoked, $lease->status);
        $this->assertNull($lease->synthetic_password_hash);
        $this->assertNull($lease->protocol_auth_secret);
        $this->assertNotNull($token->refresh()->revoked_at);
        $this->assertTrue(AuditLog::query()->where('action', 'native_proxy.lease_revoked')->where('auditable_id', $lease->id)->exists());
    }

    public function test_only_the_owner_or_an_administrator_can_manage_an_active_native_proxy_session(): void
    {
        [$owner, $session] = $this->nativeSession();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->postJson(route('query-sessions.native-proxy.credentials.store', $session), [], [
                'Idempotency-Key' => 'lease-create-key-9999',
            ])
            ->assertForbidden();

        $browserRequest = QueryRequest::factory()->queryAccess()->approved()->create([
            'requester_id' => $owner->id,
            'database_connection_id' => $session->database_connection_id,
            'access_transport' => AccessTransport::Browser,
        ]);
        $browserSession = QuerySession::factory()->create([
            'query_request_id' => $browserRequest->id,
            'user_id' => $owner->id,
            'database_connection_id' => $session->database_connection_id,
        ]);

        $this->actingAs($owner)
            ->postJson(route('query-sessions.native-proxy.credentials.store', $browserSession), [], [
                'Idempotency-Key' => 'lease-create-key-0000',
            ])
            ->assertForbidden();
    }

    public function test_expiring_a_lease_revokes_access_and_clears_its_secrets(): void
    {
        $lease = NativeProxyLease::factory()->active()->create([
            'expires_at' => now()->subMinute(),
            'synthetic_password_hash' => Hash::make('temporary-password'),
            'protocol_auth_secret' => 'temporary-password',
        ]);
        $token = NativeProxyToken::factory()->for($lease, 'lease')->create();

        $this->assertSame(1, app(LeaseWorkflow::class)->expireDue());

        $lease->refresh();
        $this->assertSame(NativeProxyLeaseStatus::Expired, $lease->status);
        $this->assertNull($lease->synthetic_password_hash);
        $this->assertNull($lease->protocol_auth_secret);
        $this->assertNotNull($token->refresh()->revoked_at);
    }

    /**
     * @return array{0: User, 1: QuerySession}
     */
    private function nativeSession(AccessMode $accessMode = AccessMode::Read): array
    {
        $connection = DatabaseConnection::factory()->postgresql()->create();
        $role = Role::factory()->developer()->create();
        $owner = User::factory()->withRole($role)->create();
        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $connection->id,
            'access_mode' => $accessMode,
            'native_proxy_access_mode' => $accessMode,
        ]);
        $request = QueryRequest::factory()->queryAccess()->approved()->create([
            'requester_id' => $owner->id,
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::QueryAccess,
            'access_transport' => AccessTransport::NativeProxy,
            'requested_access_mode' => $accessMode,
            'status' => QueryRequestStatus::Approved,
        ]);
        $session = QuerySession::factory()->create([
            'query_request_id' => $request->id,
            'user_id' => $owner->id,
            'database_connection_id' => $connection->id,
        ]);

        return [$owner, $session];
    }
}
