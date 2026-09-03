<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Enums\AccessTransport;
use App\Enums\DatabaseDriver;
use App\Enums\NativeProxyDeviceAuthorizationStatus;
use App\Enums\NativeProxyLeaseStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Models\DatabaseConnection;
use App\Models\NativeProxyConnection;
use App\Models\NativeProxyDeviceAuthorization;
use App\Models\NativeProxyLease;
use App\Models\NativeProxyToken;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\QuerySessionQuery;
use App\Models\Role;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NativeProxySessionPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_native_client_operations_without_any_plaintext_secret_in_page_props(): void
    {
        config()->set('native_proxy.cli_download_url', 'https://downloads.example.com/crucible');

        [$owner, $session, $lease] = $this->nativeSession();
        $lease->forceFill([
            'status' => NativeProxyLeaseStatus::Active,
            'synthetic_password_hash' => 'hash-not-for-props',
            'protocol_auth_secret' => 'secret-not-for-props',
        ])->save();
        $authorization = $this->authorizedDevice($lease);

        $response = $this->actingAs($owner)->get(route('query-sessions.show', $session));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('query-sessions/show')
            ->where('session.transport', AccessTransport::NativeProxy->value)
            ->where('session.native_proxy.can_manage', true)
            ->where('session.native_proxy.lease.id', $lease->id)
            ->where('session.native_proxy.authorized_devices.0.id', $authorization->id)
            ->where('session.native_proxy.authorized_devices.0.device_label', 'Test workstation')
            ->where('native_proxy_cli_download_url', 'https://downloads.example.com/crucible')
            ->missing('session.native_proxy.lease.protocol_auth_secret')
            ->missing('session.native_proxy.lease.synthetic_password_hash'));
        $response->assertDontSee('secret-not-for-props');
    }

    public function test_reviewer_can_view_native_client_audit_state_but_cannot_manage_credentials(): void
    {
        [$owner, $session] = $this->nativeSession();
        $reviewerRole = Role::factory()->create();
        $reviewer = User::factory()->withRole($reviewerRole)->create();
        RoleDatabasePermission::factory()->create([
            'role_id' => $reviewerRole->id,
            'database_connection_id' => $session->database_connection_id,
            'access_mode' => AccessMode::Read,
            'native_proxy_access_mode' => AccessMode::Read,
            'can_review' => true,
        ]);

        $this->actingAs($reviewer)
            ->get(route('query-sessions.show', $session))
            ->assertInertia(fn (Assert $page) => $page
                ->where('session.native_proxy.can_manage', false)
                ->where('session.native_proxy.lease.id', null));
        $this->actingAs($reviewer)
            ->postJson(route('query-sessions.native-proxy.credentials.store', $session), [], ['Idempotency-Key' => 'reviewer-cannot-create'])
            ->assertForbidden();

        $this->assertSame($owner->id, $session->user_id);
    }

    public function test_owner_can_poll_current_native_client_connections_without_reloading_the_page(): void
    {
        [$owner, $session, $lease] = $this->nativeSession();
        $authorization = $this->authorizedDevice($lease);
        $connection = NativeProxyConnection::factory()->active()->create([
            'lease_id' => $lease->id,
            'query_session_id' => $session->id,
            'query_request_id' => $session->query_request_id,
            'user_id' => $owner->id,
            'database_connection_id' => $session->database_connection_id,
            'client_application' => 'psql',
            'statement_count' => 2,
        ]);

        $this->actingAs($owner)
            ->getJson(route('query-sessions.native-proxy.connections.index', $session))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.0.id', $connection->id)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.client_application', 'psql')
            ->assertJsonPath('data.0.statement_count', 2)
            ->assertJsonPath('authorized_devices.0.id', $authorization->id)
            ->assertJsonPath('authorized_devices.0.device_label', 'Test workstation');
    }

    public function test_native_statement_history_is_paginated_newest_first(): void
    {
        [$owner, $session] = $this->nativeSession();
        $statements = collect(range(1, 50))->map(fn (int $position): QuerySessionQuery => QuerySessionQuery::factory()->create([
            'query_session_id' => $session->id,
            'user_id' => $owner->id,
            'native_protocol_command' => 'com_query',
            'native_sql_fingerprint' => "fingerprint-{$position}",
            'created_at' => now()->subSeconds(50 - $position),
        ]));

        $this->actingAs($owner)
            ->get(route('query-sessions.show', [
                'query_session' => $session,
                'statements_page' => 2,
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('session.native_proxy.statements.current_page', 2)
                ->where('session.native_proxy.statements.last_page', 2)
                ->where('session.native_proxy.statements.per_page', 25)
                ->where('session.native_proxy.statements.total', 50)
                ->has('session.native_proxy.statements.data', 25)
                ->where('session.native_proxy.statements.data.0.id', $statements[24]->id)
                ->where('session.native_proxy.statements.data.24.id', $statements[0]->id));
    }

    /** @return array{0: User, 1: QuerySession, 2: NativeProxyLease} */
    private function nativeSession(): array
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
        $request = QueryRequest::factory()->queryAccess()->approved()->create([
            'requester_id' => $owner->id,
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::QueryAccess,
            'access_transport' => AccessTransport::NativeProxy,
            'requested_access_mode' => AccessMode::Read,
            'status' => QueryRequestStatus::Approved,
        ]);
        $session = QuerySession::factory()->create([
            'query_request_id' => $request->id,
            'user_id' => $owner->id,
            'database_connection_id' => $connection->id,
        ]);
        $lease = NativeProxyLease::factory()->create([
            'query_session_id' => $session->id,
            'query_request_id' => $request->id,
            'user_id' => $owner->id,
            'database_connection_id' => $connection->id,
            'protocol' => DatabaseDriver::PostgreSql,
            'access_mode' => AccessMode::Read,
        ]);

        return [$owner, $session, $lease];
    }

    private function authorizedDevice(NativeProxyLease $lease): NativeProxyDeviceAuthorization
    {
        $authorization = NativeProxyDeviceAuthorization::factory()->create([
            'lease_id' => $lease->id,
            'status' => NativeProxyDeviceAuthorizationStatus::Consumed,
            'approved_at' => now()->subSecond(),
            'consumed_at' => now(),
        ]);
        NativeProxyToken::factory()->active()->create([
            'lease_id' => $lease->id,
            'device_authorization_id' => $authorization->id,
        ]);

        return $authorization;
    }
}
