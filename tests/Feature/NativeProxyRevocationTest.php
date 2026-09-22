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
use App\Events\NativeProxyLeaseRevoked;
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
use App\Services\NativeProxy\LeaseWorkflow;
use App\Services\QueryRequestWorkflow;
use App\Services\QuerySessionWorkflow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NativeProxyRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ending_a_native_client_session_revokes_tokens_connections_and_secrets(): void
    {
        [$owner, $session, $lease] = $this->activeLease();
        $device = NativeProxyDeviceAuthorization::factory()->for($lease, 'lease')->create([
            'status' => NativeProxyDeviceAuthorizationStatus::Consumed,
        ]);
        $token = NativeProxyToken::factory()->for($lease, 'lease')->for($device, 'deviceAuthorization')->create();
        $connection = NativeProxyConnection::factory()->for($lease, 'lease')->create([
            'query_session_id' => $session->id,
            'query_request_id' => $session->query_request_id,
            'user_id' => $owner->id,
            'database_connection_id' => $session->database_connection_id,
            'status' => NativeProxyConnectionStatus::Active,
        ]);

        app(QuerySessionWorkflow::class)->end($session, $owner);

        $lease->refresh();
        $this->assertSame(NativeProxyLeaseStatus::Revoked, $lease->status);
        $this->assertNull($lease->synthetic_password_hash);
        $this->assertNull($lease->protocol_auth_secret);
        $this->assertNotNull($token->refresh()->revoked_at);
        $this->assertSame(NativeProxyConnectionStatus::Revoked, $connection->refresh()->status);
    }

    public function test_connection_policy_changes_revoke_pending_attempts_and_every_active_lease_for_the_connection(): void
    {
        [$owner, $session, $lease] = $this->activeLease();
        $device = NativeProxyDeviceAuthorization::factory()->for($lease, 'lease')->create();
        $token = NativeProxyToken::factory()->for($lease, 'lease')->for($device, 'deviceAuthorization')->create();
        $attempt = NativeProxyAuthAttempt::factory()->for($lease, 'lease')->for($token, 'token')->for($device, 'deviceAuthorization')->create();
        NativeProxyConnection::factory()->for($lease, 'lease')->create([
            'query_session_id' => $session->id,
            'query_request_id' => $session->query_request_id,
            'user_id' => $owner->id,
            'database_connection_id' => $session->database_connection_id,
            'status' => NativeProxyConnectionStatus::Reserved,
        ]);

        $count = app(LeaseWorkflow::class)->revokeForDatabaseConnections(
            [$session->database_connection_id],
            $owner,
            'Database connection configuration changed.',
        );

        $this->assertSame(1, $count);
        $this->assertSame(NativeProxyLeaseStatus::Revoked, $lease->refresh()->status);
        $this->assertNotNull($token->refresh()->revoked_at);
        $this->assertSame('expired', $attempt->refresh()->status);
        $this->assertDatabaseHas('native_proxy_connections', [
            'lease_id' => $lease->id,
            'status' => NativeProxyConnectionStatus::Revoked->value,
        ]);
        $this->assertSame(0, app(LeaseWorkflow::class)->revokeForDatabaseConnections([$session->database_connection_id], $owner, 'Repeated revocation.'));
    }

    public function test_cancelling_a_query_access_request_revokes_its_native_client_lease(): void
    {
        [$owner, $session, $lease] = $this->activeLease();
        $device = NativeProxyDeviceAuthorization::factory()->for($lease, 'lease')->create();
        $token = NativeProxyToken::factory()->for($lease, 'lease')->for($device, 'deviceAuthorization')->create();

        app(QueryRequestWorkflow::class)->cancel(
            $session->queryRequest,
            $owner,
            'No longer needed.',
        );

        $this->assertSame(NativeProxyLeaseStatus::Revoked, $lease->refresh()->status);
        $this->assertNotNull($token->refresh()->revoked_at);
    }

    public function test_admin_can_delete_a_cancelled_native_client_request_and_its_proxy_records(): void
    {
        [$owner, $session, $lease] = $this->activeLease();
        $device = NativeProxyDeviceAuthorization::factory()->for($lease, 'lease')->create();
        $token = NativeProxyToken::factory()->for($lease, 'lease')->for($device, 'deviceAuthorization')->create();
        $attempt = NativeProxyAuthAttempt::factory()->for($lease, 'lease')->for($token, 'token')->for($device, 'deviceAuthorization')->create();
        $proxyConnection = NativeProxyConnection::factory()->for($lease, 'lease')->create([
            'query_session_id' => $session->id,
            'query_request_id' => $session->query_request_id,
            'user_id' => $owner->id,
            'database_connection_id' => $session->database_connection_id,
        ]);

        app(QueryRequestWorkflow::class)->cancel($session->queryRequest, $owner, 'No longer needed.');

        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();
        $this->actingAs($admin)
            ->delete(route('query-requests.destroy', $session->queryRequest))
            ->assertRedirect(route('query-requests.index'));

        $this->assertModelMissing($session->queryRequest);
        $this->assertModelMissing($session);
        $this->assertModelMissing($lease);
        $this->assertModelMissing($device);
        $this->assertModelMissing($token);
        $this->assertModelMissing($attempt);
        $this->assertModelMissing($proxyConnection);
    }

    public function test_revocation_event_is_deferred_until_the_database_transaction_commits(): void
    {
        $event = new NativeProxyLeaseRevoked(['lease-1'], 'Policy changed.');

        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertSame(['lease-1'], $event->leaseIds);
    }

    public function test_audit_log_native_proxy_filter_and_export_include_only_native_proxy_events(): void
    {
        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();
        AuditLog::factory()->create(['action' => 'native_proxy.lease_revoked']);
        AuditLog::factory()->create(['action' => 'query_request.created']);

        $this->actingAs($admin)
            ->get(route('audit-logs.index', ['event_family' => 'native_proxy']))
            ->assertInertia(fn ($page) => $page
                ->has('audit_logs.data', 1)
                ->where('audit_logs.data.0.action', 'native_proxy.lease_revoked'));

        $export = $this->actingAs($admin)
            ->get(route('audit-logs.export', ['event_family' => 'native_proxy']))
            ->assertOk();

        $csv = $export->streamedContent();

        $this->assertStringContainsString('native_proxy.lease_revoked', $csv);
        $this->assertStringNotContainsString('query_request.created', $csv);
    }

    /** @return array{0: User, 1: QuerySession, 2: NativeProxyLease} */
    private function activeLease(): array
    {
        $connection = DatabaseConnection::factory()->postgresql()->create();
        $role = Role::factory()->developer()->create();
        $owner = User::factory()->withRole($role)->create();
        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'native_proxy_access_mode' => AccessMode::Read,
        ]);
        $request = QueryRequest::factory()->queryAccess()->create([
            'requester_id' => $owner->id,
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::QueryAccess,
            'access_transport' => AccessTransport::NativeProxy,
            'requested_access_mode' => AccessMode::Read,
            'status' => QueryRequestStatus::Running,
        ]);
        $session = QuerySession::factory()->create([
            'query_request_id' => $request->id,
            'user_id' => $owner->id,
            'database_connection_id' => $connection->id,
        ]);
        $lease = NativeProxyLease::factory()->active()->create([
            'query_session_id' => $session->id,
            'query_request_id' => $request->id,
            'user_id' => $owner->id,
            'database_connection_id' => $connection->id,
            'protocol' => DatabaseDriver::PostgreSql,
            'access_mode' => AccessMode::Read,
            'synthetic_password_hash' => Hash::make('temporary-password'),
            'protocol_auth_secret' => 'temporary-password',
        ]);

        return [$owner, $session, $lease];
    }
}
