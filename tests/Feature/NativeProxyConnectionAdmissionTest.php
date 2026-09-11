<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Enums\AccessTransport;
use App\Enums\DatabaseDriver;
use App\Enums\NativeProxyConnectionStatus;
use App\Enums\NativeProxyDeviceAuthorizationStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Models\DatabaseConnection;
use App\Models\NativeProxyConnection;
use App\Models\NativeProxyDeviceAuthorization;
use App\Models\NativeProxyLease;
use App\Models\NativeProxyToken;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\Role;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use App\Services\NativeProxy\AuthorizedTunnel;
use App\Services\NativeProxy\ConnectionAdmission;
use App\Services\NativeProxy\ConsumeAuthAttemptData;
use App\Services\NativeProxy\TunnelAuthorizationData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NativeProxyConnectionAdmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tunnel_authorization_binds_an_active_token_to_the_lease_device_protocol_and_connection(): void
    {
        [$lease, $deviceAuthorization, $token, $bearerToken] = $this->activeNativeToken();

        $authorization = app(ConnectionAdmission::class)->authorizeTunnel(new TunnelAuthorizationData(
            $lease->id,
            $deviceAuthorization->id,
            'proxy-connection-0001',
            'proxy-01',
            DatabaseDriver::PostgreSql,
            hash('sha256', $bearerToken),
        ));

        $this->assertSame($lease->id, $authorization->leaseId);
        $this->assertSame('postgresql', $authorization->protocol);
        $this->assertSame($token->id, $lease->tokens()->firstOrFail()->id);
        $this->assertDatabaseHas('native_proxy_auth_attempts', [
            'id' => $authorization->authAttemptId,
            'lease_id' => $lease->id,
            'token_id' => $token->id,
            'proxy_connection_id' => 'proxy-connection-0001',
            'protocol' => DatabaseDriver::PostgreSql->value,
        ]);
    }

    public function test_tunnel_authorization_fails_closed_for_revoked_tokens_and_wrong_bindings(): void
    {
        [$lease, $deviceAuthorization, $token, $bearerToken] = $this->activeNativeToken();
        $token->forceFill(['revoked_at' => now()])->save();

        $this->expectException(ValidationException::class);

        app(ConnectionAdmission::class)->authorizeTunnel(new TunnelAuthorizationData(
            $lease->id,
            $deviceAuthorization->id,
            'proxy-connection-0002',
            'proxy-01',
            DatabaseDriver::PostgreSql,
            hash('sha256', $bearerToken),
        ));
    }

    public function test_connection_admission_consumes_a_short_lived_attempt_and_reserves_a_lease_slot(): void
    {
        [$lease, $deviceAuthorization, , $bearerToken] = $this->activeNativeToken();
        $lease->forceFill([
            'synthetic_username' => 'crucible_test_user',
            'synthetic_password_hash' => Hash::make('temporary-native-password'),
        ])->save();
        $admission = app(ConnectionAdmission::class);
        $tunnel = $admission->authorizeTunnel(new TunnelAuthorizationData(
            $lease->id,
            $deviceAuthorization->id,
            'proxy-connection-0003',
            'proxy-01',
            DatabaseDriver::PostgreSql,
            hash('sha256', $bearerToken),
        ));

        $connection = $admission->authorizeConnection(new ConsumeAuthAttemptData(
            $tunnel->authAttemptId,
            'proxy-01',
            'crucible_test_user',
            'temporary-native-password',
            DatabaseDriver::PostgreSql,
        ));

        $this->assertSame($lease->id, $connection->leaseId);
        $this->assertTrue($connection->readOnly);
        $this->assertDatabaseHas('native_proxy_connections', [
            'id' => $connection->connectionId,
            'status' => 'reserved',
        ]);
        $this->assertDatabaseHas('native_proxy_auth_attempts', [
            'id' => $tunnel->authAttemptId,
            'status' => 'consumed',
        ]);
        $this->assertDatabaseHas('native_proxy_connections', [
            'id' => $connection->connectionId,
            'cli_version' => $deviceAuthorization->cli_version,
            'operating_system' => $deviceAuthorization->operating_system,
            'architecture' => $deviceAuthorization->architecture,
            'upstream_tls_verified' => false,
        ]);
    }

    public function test_connection_admission_enforces_the_distributed_per_user_limit(): void
    {
        config()->set('native_proxy.max_connections_per_user', 1);
        [$lease, $deviceAuthorization, , $bearerToken] = $this->activeNativeToken();
        NativeProxyConnection::factory()->active()->create(['user_id' => $lease->user_id]);
        [$admission, $tunnel] = $this->pendingConnectionAdmission($lease, $deviceAuthorization, $bearerToken, 'proxy-connection-user-limit');

        $this->expectException(ValidationException::class);

        $admission->authorizeConnection(new ConsumeAuthAttemptData(
            $tunnel->authAttemptId,
            'proxy-01',
            'crucible_test_user',
            'temporary-native-password',
            DatabaseDriver::PostgreSql,
        ));
    }

    public function test_connection_admission_enforces_the_distributed_global_limit(): void
    {
        config()->set('native_proxy.max_connections', 1);
        [$lease, $deviceAuthorization, , $bearerToken] = $this->activeNativeToken();
        NativeProxyConnection::factory()->active()->create();
        [$admission, $tunnel] = $this->pendingConnectionAdmission($lease, $deviceAuthorization, $bearerToken, 'proxy-connection-global-limit');

        $this->expectException(ValidationException::class);

        $admission->authorizeConnection(new ConsumeAuthAttemptData(
            $tunnel->authAttemptId,
            'proxy-01',
            'crucible_test_user',
            'temporary-native-password',
            DatabaseDriver::PostgreSql,
        ));
    }

    public function test_expired_reservations_are_failed_and_do_not_consume_capacity(): void
    {
        config()->set('native_proxy.max_connections', 1);
        [$lease, $deviceAuthorization, , $bearerToken] = $this->activeNativeToken();
        $expiredConnection = NativeProxyConnection::factory()->create([
            'status' => NativeProxyConnectionStatus::Reserved,
            'reservation_expires_at' => now()->subSecond(),
        ]);
        [$admission, $tunnel] = $this->pendingConnectionAdmission($lease, $deviceAuthorization, $bearerToken, 'proxy-connection-after-expiry');

        $reserved = $admission->authorizeConnection(new ConsumeAuthAttemptData(
            $tunnel->authAttemptId,
            'proxy-01',
            'crucible_test_user',
            'temporary-native-password',
            DatabaseDriver::PostgreSql,
        ));

        $this->assertDatabaseHas('native_proxy_connections', [
            'id' => $expiredConnection->id,
            'status' => NativeProxyConnectionStatus::Failed->value,
            'disconnect_reason' => 'Native proxy connection reservation expired.',
        ]);
        $this->assertDatabaseHas('native_proxy_connections', [
            'id' => $reserved->connectionId,
            'status' => NativeProxyConnectionStatus::Reserved->value,
        ]);
    }

    public function test_heartbeat_closes_a_connection_when_its_bearer_token_is_revoked(): void
    {
        [$lease, $deviceAuthorization, $token, $bearerToken] = $this->activeNativeToken();
        $lease->forceFill([
            'synthetic_username' => 'crucible_test_user',
            'synthetic_password_hash' => Hash::make('temporary-native-password'),
        ])->save();
        $admission = app(ConnectionAdmission::class);
        $tunnel = $admission->authorizeTunnel(new TunnelAuthorizationData(
            $lease->id,
            $deviceAuthorization->id,
            'proxy-connection-heartbeat',
            'proxy-01',
            DatabaseDriver::PostgreSql,
            hash('sha256', $bearerToken),
        ));
        $reserved = $admission->authorizeConnection(new ConsumeAuthAttemptData(
            $tunnel->authAttemptId,
            'proxy-01',
            'crucible_test_user',
            'temporary-native-password',
            DatabaseDriver::PostgreSql,
        ));
        $connection = NativeProxyConnection::query()->findOrFail($reserved->connectionId);
        $admission->markAuthenticated($connection, [
            'client_application' => 'psql',
            'client_version' => '17.0',
        ]);
        $token->forceFill(['revoked_at' => now()])->save();

        $decision = $admission->heartbeat($connection->fresh());

        $this->assertFalse($decision->continueConnection);
        $this->assertDatabaseHas('native_proxy_connections', [
            'id' => $connection->id,
            'status' => NativeProxyConnectionStatus::Closed->value,
        ]);
    }

    public function test_upstream_material_is_only_available_after_reservation_and_connection_metadata_and_traffic_are_recorded(): void
    {
        [$lease, $deviceAuthorization, , $bearerToken] = $this->activeNativeToken();
        [$admission, $tunnel] = $this->pendingConnectionAdmission($lease, $deviceAuthorization, $bearerToken, 'proxy-connection-upstream');
        $reserved = $admission->authorizeConnection(new ConsumeAuthAttemptData(
            $tunnel->authAttemptId,
            'proxy-01',
            'crucible_test_user',
            'temporary-native-password',
            DatabaseDriver::PostgreSql,
        ));
        $connection = NativeProxyConnection::query()->findOrFail($reserved->connectionId);

        $upstream = $admission->upstreamMaterial($connection);
        $admission->markAuthenticated($connection, [
            'client_application' => 'DBeaver',
            'client_version' => '24.1',
        ]);
        $admission->recordTraffic($connection, 128, 256);

        $this->assertSame($lease->databaseConnection->host, $upstream->host);
        $this->assertSame($lease->databaseConnection->tls_ca_certificate, $upstream->tlsCaCertificate);
        $this->assertDatabaseHas('native_proxy_connections', [
            'id' => $connection->id,
            'status' => NativeProxyConnectionStatus::Active->value,
            'client_application' => 'DBeaver',
            'client_version' => '24.1',
            'bytes_received' => 128,
            'bytes_sent' => 256,
        ]);
    }

    /** @return array{0: NativeProxyLease, 1: NativeProxyDeviceAuthorization, 2: NativeProxyToken, 3: string} */
    private function activeNativeToken(): array
    {
        $connection = DatabaseConnection::factory()->postgresql()->create();
        $role = Role::factory()->developer()->create();
        $user = User::factory()->withRole($role)->create();
        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'native_proxy_access_mode' => AccessMode::Read,
        ]);
        $request = QueryRequest::factory()->queryAccess()->approved()->create([
            'requester_id' => $user->id,
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::QueryAccess,
            'access_transport' => AccessTransport::NativeProxy,
            'requested_access_mode' => AccessMode::Read,
            'status' => QueryRequestStatus::Approved,
        ]);
        $session = QuerySession::factory()->create([
            'query_request_id' => $request->id,
            'user_id' => $user->id,
            'database_connection_id' => $connection->id,
        ]);
        $lease = NativeProxyLease::factory()->active()->create([
            'query_session_id' => $session->id,
            'query_request_id' => $request->id,
            'user_id' => $user->id,
            'database_connection_id' => $connection->id,
            'protocol' => DatabaseDriver::PostgreSql,
            'access_mode' => AccessMode::Read,
        ]);
        $deviceAuthorization = NativeProxyDeviceAuthorization::factory()
            ->for($lease, 'lease')
            ->create(['status' => NativeProxyDeviceAuthorizationStatus::Consumed]);
        $bearerToken = 'native-bearer-token-for-test';
        $token = NativeProxyToken::factory()
            ->for($lease, 'lease')
            ->for($deviceAuthorization, 'deviceAuthorization')
            ->create(['token_hash' => hash('sha256', $bearerToken)]);

        return [$lease, $deviceAuthorization, $token, $bearerToken];
    }

    /** @return array{0: ConnectionAdmission, 1: AuthorizedTunnel} */
    private function pendingConnectionAdmission(NativeProxyLease $lease, NativeProxyDeviceAuthorization $deviceAuthorization, string $bearerToken, string $proxyConnectionId): array
    {
        $lease->forceFill([
            'synthetic_username' => 'crucible_test_user',
            'synthetic_password_hash' => Hash::make('temporary-native-password'),
        ])->save();
        $admission = app(ConnectionAdmission::class);
        $tunnel = $admission->authorizeTunnel(new TunnelAuthorizationData(
            $lease->id,
            $deviceAuthorization->id,
            $proxyConnectionId,
            'proxy-01',
            DatabaseDriver::PostgreSql,
            hash('sha256', $bearerToken),
        ));

        return [$admission, $tunnel];
    }
}
