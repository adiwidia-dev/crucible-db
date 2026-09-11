<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Enums\AccessTransport;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Models\ConnectionGroup;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\Role;
use App\Models\RoleConnectionGroupPolicy;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use App\Services\ApplicationSettings;
use App\Services\DatabaseQueryExecutor;
use App\Services\DatabaseTlsMaterializer;
use App\Services\QueryRequestWorkflow;
use App\Services\QuerySessionWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QueryAccessPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_proxy_policy_casts_are_persisted(): void
    {
        $request = QueryRequest::factory()->create();
        $permission = RoleDatabasePermission::factory()->create([
            'access_mode' => AccessMode::Write,
            'query_access_mode' => AccessMode::Write,
            'native_proxy_access_mode' => AccessMode::Read,
        ]);

        $this->assertSame(AccessTransport::Browser, $request->access_transport);
        $this->assertSame(AccessMode::Read, $permission->native_proxy_access_mode);
    }

    public function test_query_request_detail_uses_status_aware_approval_labels(): void
    {
        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create([
            'name' => 'Crucible Admin',
        ]);
        $connection = DatabaseConnection::factory()->create();

        $draft = QueryRequest::factory()->queryAccess()->create([
            'requester_id' => $admin->id,
            'database_connection_id' => $connection->id,
            'status' => QueryRequestStatus::Draft,
            'requires_approval' => true,
        ]);
        $draft->accessConnections()->sync([$connection->id]);

        $this->actingAs($admin)
            ->get(route('query-requests.show', $draft))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('query_request.approval_label', 'Not submitted'));

        $pending = QueryRequest::factory()->queryAccess()->create([
            'requester_id' => $admin->id,
            'database_connection_id' => $connection->id,
            'status' => QueryRequestStatus::PendingReview,
            'requires_approval' => true,
        ]);
        $pending->accessConnections()->sync([$connection->id]);

        $this->actingAs($admin)
            ->get(route('query-requests.show', $pending))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('query_request.approval_label', 'Awaiting decision'));

        $approved = QueryRequest::factory()->queryAccess()->approved()->create([
            'requester_id' => $admin->id,
            'database_connection_id' => $connection->id,
            'requires_approval' => true,
            'approved_by_id' => $admin->id,
        ]);
        $approved->accessConnections()->sync([$connection->id]);

        $this->actingAs($admin)
            ->get(route('query-requests.show', $approved))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('query_request.approval_label', 'Approved by Crucible Admin'));
    }

    public function test_native_proxy_permission_is_denied_for_an_inactive_connection_before_admin_resolution(): void
    {
        $user = User::factory()->withRole(Role::factory()->admin()->create())->create();
        $connection = DatabaseConnection::factory()->create(['is_active' => false]);

        $permission = $user->effectiveNativeProxyPermissionFor($connection, QueryType::Write);

        $this->assertSame(AccessMode::None, $permission['native_proxy_access_mode']);
    }

    public function test_native_proxy_permission_is_denied_for_an_inactive_connection_before_role_resolution(): void
    {
        $role = Role::factory()->developer()->create();
        $user = User::factory()->withRole($role)->create();
        $connection = DatabaseConnection::factory()->create(['is_active' => false]);
        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Write,
            'native_proxy_access_mode' => AccessMode::Write,
        ]);

        $permission = $user->effectiveNativeProxyPermissionFor($connection, QueryType::Write);

        $this->assertSame(AccessMode::None, $permission['native_proxy_access_mode']);
    }

    public function test_native_proxy_permission_uses_direct_precedence_group_restriction_and_maximum_access_cap(): void
    {
        $role = Role::factory()->developer()->create();
        $user = User::factory()->withRole($role)->create();
        $connection = DatabaseConnection::factory()->create();
        $firstGroup = ConnectionGroup::factory()->create();
        $secondGroup = ConnectionGroup::factory()->create();
        $firstGroup->databaseConnections()->sync([$connection->id]);
        $secondGroup->databaseConnections()->sync([$connection->id]);

        RoleConnectionGroupPolicy::factory()->create([
            'role_id' => $role->id,
            'connection_group_id' => $firstGroup->id,
            'access_mode' => AccessMode::Write,
            'query_access_mode' => AccessMode::Read,
            'native_proxy_access_mode' => AccessMode::Write,
        ]);
        RoleConnectionGroupPolicy::factory()->create([
            'role_id' => $role->id,
            'connection_group_id' => $secondGroup->id,
            'access_mode' => AccessMode::Write,
            'query_access_mode' => AccessMode::Write,
            'native_proxy_access_mode' => AccessMode::Read,
        ]);

        $this->assertSame(
            AccessMode::Read,
            $user->effectiveNativeProxyPermissionFor($connection, QueryType::Read)['native_proxy_access_mode'],
        );
        $this->assertSame(
            AccessMode::None,
            $user->effectiveNativeProxyPermissionFor($connection, QueryType::Write)['native_proxy_access_mode'],
        );

        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'query_access_mode' => AccessMode::Write,
            'native_proxy_access_mode' => AccessMode::Write,
        ]);
        $user->refresh();

        $effectivePermission = $user->effectiveDatabasePermission($connection);

        $this->assertSame(AccessMode::Read, $effectivePermission['access_mode']);
        $this->assertSame(AccessMode::Read, $effectivePermission['native_proxy_access_mode']);
        $this->assertSame(AccessMode::Read, $user->effectiveNativeProxyPermissionFor($connection, QueryType::Read)['native_proxy_access_mode']);
        $this->assertSame(AccessMode::None, $user->effectiveNativeProxyPermissionFor($connection, QueryType::Write)['native_proxy_access_mode']);
    }

    public function test_native_proxy_access_mode_is_independent_from_query_access_mode(): void
    {
        $role = Role::factory()->developer()->create();
        $user = User::factory()->withRole($role)->create();
        $connection = DatabaseConnection::factory()->create();
        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Write,
            'query_access_mode' => AccessMode::Read,
            'native_proxy_access_mode' => AccessMode::Write,
        ]);

        $this->assertSame(
            AccessMode::Read,
            $user->effectiveQueryAccessPermissionFor($connection, QueryType::Read)['query_access_mode'],
        );
        $this->assertSame(
            AccessMode::None,
            $user->effectiveQueryAccessPermissionFor($connection, QueryType::Write)['query_access_mode'],
        );
        $this->assertSame(
            AccessMode::Write,
            $user->effectiveNativeProxyPermissionFor($connection, QueryType::Write)['native_proxy_access_mode'],
        );
    }

    public function test_disabled_role_workflows_are_excluded_from_request_options(): void
    {
        $role = Role::factory()->developer()->create();
        $user = User::factory()->withRole($role)->create();
        $connection = DatabaseConnection::factory()->create();
        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'query_access_mode' => AccessMode::None,
            'native_proxy_access_mode' => AccessMode::None,
        ]);

        $this->actingAs($user)
            ->get(route('query-requests.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('connections.0.can_query_access_read', false)
                ->where('connections.0.can_native_proxy_read', false));
    }

    public function test_globally_disabled_access_workflows_are_hidden_and_cannot_be_created(): void
    {
        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();
        $connection = DatabaseConnection::factory()->create();

        app(ApplicationSettings::class)->put([
            ApplicationSettings::QueryAccessEnabled => false,
            ApplicationSettings::NativeClientAccessEnabled => false,
        ]);

        $this->actingAs($admin)
            ->get(route('query-requests.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('access_features.query_access_enabled', false)
                ->where('access_features.native_client_access_enabled', false));

        $payload = [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'requested_access_mode' => AccessMode::Read->value,
            'database_connection_ids' => [$connection->id],
            'title' => 'Temporary investigation access',
            'access_duration_minutes' => 20,
        ];

        $this->actingAs($admin)
            ->post(route('query-requests.store'), [
                ...$payload,
                'access_transport' => AccessTransport::Browser->value,
            ])
            ->assertSessionHasErrors('request_kind');

        $this->actingAs($admin)
            ->post(route('query-requests.store'), [
                ...$payload,
                'access_transport' => AccessTransport::NativeProxy->value,
            ])
            ->assertSessionHasErrors('access_transport');
    }

    public function test_globally_disabled_access_workflow_cannot_start_a_new_session(): void
    {
        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();
        $connection = DatabaseConnection::factory()->create();
        $queryRequest = QueryRequest::factory()->queryAccess()->approved()->create([
            'requester_id' => $admin->id,
            'database_connection_id' => $connection->id,
            'access_transport' => AccessTransport::Browser,
        ]);
        $queryRequest->accessConnections()->sync([$connection->id]);

        app(ApplicationSettings::class)->put([
            ApplicationSettings::QueryAccessEnabled => false,
        ]);

        $this->expectException(ValidationException::class);

        app(QuerySessionWorkflow::class)->start($queryRequest, $admin);
    }

    public function test_native_client_access_uses_native_policy_and_persists_one_target_transport(): void
    {
        $role = Role::factory()->developer()->create();
        $user = User::factory()->withRole($role)->create();
        $connection = DatabaseConnection::factory()->create();
        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Write,
            'query_access_mode' => AccessMode::None,
            'native_proxy_access_mode' => AccessMode::Read,
            'read_requires_approval' => true,
        ]);

        $queryRequest = app(QueryRequestWorkflow::class)->create($user, [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'access_transport' => AccessTransport::NativeProxy->value,
            'requested_access_mode' => AccessMode::Read->value,
            'database_connection_ids' => [$connection->id],
            'title' => 'Investigate through a native client',
            'access_duration_minutes' => 20,
        ]);

        $this->assertSame(AccessTransport::NativeProxy, $queryRequest->access_transport);
        $this->assertSame([$connection->id], $queryRequest->accessConnections()->pluck('database_connections.id')->all());
        $this->assertSame(QueryRequestStatus::PendingReview, $queryRequest->status);
    }

    public function test_native_client_access_rejects_multiple_or_inactive_targets_with_field_errors(): void
    {
        $role = Role::factory()->developer()->create();
        $user = User::factory()->withRole($role)->create();
        $firstConnection = DatabaseConnection::factory()->create();
        $inactiveConnection = DatabaseConnection::factory()->create(['is_active' => false]);

        foreach ([$firstConnection, $inactiveConnection] as $connection) {
            RoleDatabasePermission::factory()->create([
                'role_id' => $role->id,
                'database_connection_id' => $connection->id,
                'access_mode' => AccessMode::Write,
                'native_proxy_access_mode' => AccessMode::Write,
            ]);
        }

        $this->actingAs($user)
            ->post(route('query-requests.store'), [
                'request_kind' => QueryRequestKind::QueryAccess->value,
                'access_transport' => AccessTransport::NativeProxy->value,
                'requested_access_mode' => AccessMode::Read->value,
                'database_connection_ids' => [$firstConnection->id, $inactiveConnection->id],
                'title' => 'Investigate through a native client',
                'access_duration_minutes' => 20,
            ])
            ->assertSessionHasErrors('database_connection_ids');

        $this->actingAs($user)
            ->post(route('query-requests.store'), [
                'request_kind' => QueryRequestKind::QueryAccess->value,
                'access_transport' => AccessTransport::NativeProxy->value,
                'requested_access_mode' => AccessMode::Read->value,
                'database_connection_ids' => [$inactiveConnection->id],
                'title' => 'Investigate through a native client',
                'access_duration_minutes' => 20,
            ])
            ->assertSessionHasErrors('database_connection_ids');
    }

    public function test_native_client_access_is_not_valid_for_deployment_batches(): void
    {
        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();
        $connection = DatabaseConnection::factory()->create();

        $this->actingAs($admin)
            ->post(route('query-requests.store'), [
                'request_kind' => QueryRequestKind::SingleExecution->value,
                'access_transport' => AccessTransport::NativeProxy->value,
                'title' => 'Unsafe transport combination',
                'statements' => [[
                    'database_connection_id' => $connection->id,
                    'sql' => 'select 1',
                ]],
            ])
            ->assertSessionHasErrors('access_transport');
    }

    public function test_native_client_access_renewal_preserves_its_transport(): void
    {
        $role = Role::factory()->developer()->create();
        $user = User::factory()->withRole($role)->create();
        $connection = DatabaseConnection::factory()->create();
        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Write,
            'native_proxy_access_mode' => AccessMode::Read,
            'read_requires_approval' => false,
        ]);
        $queryRequest = QueryRequest::factory()->queryAccess()->create([
            'requester_id' => $user->id,
            'database_connection_id' => $connection->id,
            'access_transport' => AccessTransport::NativeProxy,
            'requested_access_mode' => AccessMode::Read,
            'status' => QueryRequestStatus::Completed,
            'completed_at' => now(),
        ]);
        $queryRequest->accessConnections()->sync([$connection->id]);

        $renewal = app(QueryRequestWorkflow::class)->retry($queryRequest, $user);

        $this->assertNotSame($queryRequest->id, $renewal->id);
        $this->assertSame(AccessTransport::NativeProxy, $renewal->access_transport);
    }

    public function test_native_client_session_start_rechecks_native_policy_instead_of_browser_query_access_policy(): void
    {
        $role = Role::factory()->developer()->create();
        $user = User::factory()->withRole($role)->create();
        $connection = DatabaseConnection::factory()->create();
        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Write,
            'query_access_mode' => AccessMode::None,
            'native_proxy_access_mode' => AccessMode::Read,
            'read_requires_approval' => false,
        ]);

        $queryRequest = app(QueryRequestWorkflow::class)->create($user, [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'access_transport' => AccessTransport::NativeProxy->value,
            'requested_access_mode' => AccessMode::Read->value,
            'database_connection_ids' => [$connection->id],
            'title' => 'Investigate through a native client',
            'access_duration_minutes' => 20,
        ]);

        $session = app(QuerySessionWorkflow::class)->start($queryRequest, $user);

        $this->assertSame($queryRequest->id, $session->query_request_id);
    }

    public function test_native_client_access_transport_is_immutable_after_creation(): void
    {
        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();
        $connection = DatabaseConnection::factory()->create();
        $queryRequest = QueryRequest::factory()->queryAccess()->create([
            'requester_id' => $admin->id,
            'database_connection_id' => $connection->id,
            'access_transport' => AccessTransport::NativeProxy,
            'requested_access_mode' => AccessMode::Read,
        ]);
        $queryRequest->accessConnections()->sync([$connection->id]);

        try {
            app(QueryRequestWorkflow::class)->update($queryRequest, $admin, [
                'request_kind' => QueryRequestKind::QueryAccess->value,
                'access_transport' => AccessTransport::Browser->value,
                'requested_access_mode' => AccessMode::Read->value,
                'database_connection_ids' => [$connection->id],
                'title' => $queryRequest->title,
                'access_duration_minutes' => 20,
            ]);
            $this->fail('The transport must not change after native access is requested.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'The request transport cannot be changed after creation.',
                $exception->errors()['access_transport'][0],
            );
        }
    }

    public function test_native_client_access_cannot_be_saved_as_a_draft(): void
    {
        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();
        $connection = DatabaseConnection::factory()->create();

        $this->actingAs($admin)
            ->post(route('query-requests.store'), [
                'intent' => 'draft',
                'request_kind' => QueryRequestKind::QueryAccess->value,
                'access_transport' => AccessTransport::NativeProxy->value,
                'requested_access_mode' => AccessMode::Read->value,
                'database_connection_ids' => [$connection->id],
                'title' => 'Draft native access',
                'access_duration_minutes' => 20,
            ])
            ->assertSessionHasErrors('intent');
    }

    public function test_read_and_write_role_policies_are_resolved_for_the_requested_operation(): void
    {
        [$user, $connection] = $this->userWithSeparatedReadAndWritePolicies();
        $workflow = app(QueryRequestWorkflow::class);

        $readBatch = $workflow->create($user, [
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Read employee records',
            'statements' => [[
                'database_connection_id' => $connection->id,
                'sql' => 'select * from employees limit 10',
            ]],
        ]);
        $writeBatch = $workflow->create($user, [
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Update employee records',
            'statements' => [[
                'database_connection_id' => $connection->id,
                'sql' => 'update employees set active = 1 where id = 1',
            ]],
        ]);
        $readSessionRequest = $workflow->create($user, [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'requested_access_mode' => AccessMode::Read->value,
            'database_connection_ids' => [$connection->id],
            'title' => 'Investigate employee records',
            'access_duration_minutes' => 20,
        ]);
        $writeSessionRequest = $workflow->create($user, [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'requested_access_mode' => AccessMode::Write->value,
            'database_connection_ids' => [$connection->id],
            'title' => 'Correct employee records',
            'access_duration_minutes' => 20,
        ]);

        $this->assertSame(QueryRequestStatus::Approved, $readBatch->status);
        $this->assertFalse($readBatch->requires_approval);
        $this->assertSame(QueryRequestStatus::PendingReview, $writeBatch->status);
        $this->assertTrue($writeBatch->requires_approval);
        $this->assertSame(QueryRequestStatus::Approved, $readSessionRequest->status);
        $this->assertSame(AccessMode::Read, $readSessionRequest->requested_access_mode);
        $this->assertSame(QueryRequestStatus::PendingReview, $writeSessionRequest->status);
        $this->assertSame(AccessMode::Write, $writeSessionRequest->requested_access_mode);
    }

    public function test_read_only_query_access_session_blocks_data_changing_sql(): void
    {
        [$user, $connection] = $this->userWithSeparatedReadAndWritePolicies();
        $request = app(QueryRequestWorkflow::class)->create($user, [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'requested_access_mode' => AccessMode::Read->value,
            'database_connection_ids' => [$connection->id],
            'title' => 'Investigate employee records',
            'access_duration_minutes' => 20,
        ]);
        $session = app(QuerySessionWorkflow::class)->start($request, $user);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('This is a read-only session.');

        app(QuerySessionWorkflow::class)->execute(
            $session,
            $user,
            'update employees set active = 1 where id = 1',
            $connection,
        );
    }

    public function test_write_deployment_access_defaults_query_access_to_read_only(): void
    {
        $role = Role::factory()->developer()->create();
        $user = User::factory()->withRole($role)->create();
        $connection = DatabaseConnection::factory()->create();
        RoleDatabasePermission::factory()->write()->create([
            'role_id' => $role->id,
            'database_connection_id' => $connection->id,
        ]);

        $deploymentBatch = app(QueryRequestWorkflow::class)->create($user, [
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Correct employee records',
            'statements' => [[
                'database_connection_id' => $connection->id,
                'sql' => 'UPDATE employees SET active = 1 WHERE id = 1',
            ]],
        ]);

        $this->assertSame(QueryRequestStatus::PendingReview, $deploymentBatch->status);

        try {
            app(QueryRequestWorkflow::class)->create($user, [
                'request_kind' => QueryRequestKind::QueryAccess->value,
                'requested_access_mode' => AccessMode::Write->value,
                'database_connection_ids' => [$connection->id],
                'title' => 'Correct employee records interactively',
                'access_duration_minutes' => 20,
            ]);
            $this->fail('Write Query Access should require an explicit policy opt-in.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Your role is not allowed to request the selected session access level for every selected database.',
                $exception->errors()['database_connection_ids'][0],
            );
        }

        $readSession = app(QueryRequestWorkflow::class)->create($user, [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'requested_access_mode' => AccessMode::Read->value,
            'database_connection_ids' => [$connection->id],
            'title' => 'Inspect employee records',
            'access_duration_minutes' => 20,
        ]);

        $this->assertSame(QueryRequestStatus::PendingReview, $readSession->status);
        $this->assertSame(AccessMode::Read, $readSession->requested_access_mode);
    }

    public function test_direct_connection_query_access_policy_overrides_group_policy(): void
    {
        $role = Role::factory()->developer()->create();
        $user = User::factory()->withRole($role)->create();
        $connection = DatabaseConnection::factory()->create();
        $connectionGroup = ConnectionGroup::factory()->create();
        $connectionGroup->databaseConnections()->sync([$connection->id]);
        RoleConnectionGroupPolicy::factory()->create([
            'role_id' => $role->id,
            'connection_group_id' => $connectionGroup->id,
            'access_mode' => AccessMode::Write,
            'query_access_mode' => AccessMode::Write,
        ]);
        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Write,
            'query_access_mode' => AccessMode::Read,
        ]);

        $this->assertSame(
            AccessMode::Read,
            $user->effectiveQueryAccessPermissionFor($connection, QueryType::Read)['query_access_mode'],
        );
        $this->assertSame(
            AccessMode::None,
            $user->effectiveQueryAccessPermissionFor($connection, QueryType::Write)['query_access_mode'],
        );
    }

    public function test_query_access_session_rejects_multiple_sql_statements(): void
    {
        [$user, $connection] = $this->userWithSeparatedReadAndWritePolicies();
        $request = app(QueryRequestWorkflow::class)->create($user, [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'requested_access_mode' => AccessMode::Read->value,
            'database_connection_ids' => [$connection->id],
            'title' => 'Inspect employee records',
            'access_duration_minutes' => 20,
        ]);
        $session = app(QuerySessionWorkflow::class)->start($request, $user);

        $this->actingAs($user)
            ->post(route('query-sessions.queries.store', $session), [
                'database_connection_id' => $connection->id,
                'sql' => "select * from employees limit 1;\nselect * from teams limit 1;",
            ])
            ->assertRedirect()
            ->assertSessionHasErrors([
                'sql' => 'Only one SQL statement may be submitted per request.',
            ]);
    }

    public function test_query_access_creation_persists_the_selected_session_access_level(): void
    {
        [$user, $connection] = $this->userWithSeparatedReadAndWritePolicies();

        $response = $this->actingAs($user)->post(route('query-requests.store'), [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'requested_access_mode' => AccessMode::Read->value,
            'database_connection_ids' => [$connection->id],
            'title' => 'Investigate staging data',
            'access_duration_minutes' => 20,
        ]);

        $this->assertDatabaseHas('query_requests', [
            'requester_id' => $user->id,
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'requested_access_mode' => AccessMode::Read->value,
            'requires_approval' => false,
        ]);

        $queryRequest = QueryRequest::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('query-requests.show', $queryRequest));
    }

    public function test_write_session_requires_approval_and_enforces_its_configured_duration_limit(): void
    {
        [$user, $connection] = $this->userWithSeparatedReadAndWritePolicies();

        try {
            app(QueryRequestWorkflow::class)->create($user, [
                'request_kind' => QueryRequestKind::QueryAccess->value,
                'requested_access_mode' => AccessMode::Write->value,
                'database_connection_ids' => [$connection->id],
                'title' => 'Long write session',
                'access_duration_minutes' => 31,
            ]);
            $this->fail('Write session duration should be constrained by the selected policy.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Write sessions on Staging Primary are limited to 30 minutes.',
                $exception->errors()['access_duration_minutes'][0],
            );
        }

        $request = app(QueryRequestWorkflow::class)->create($user, [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'requested_access_mode' => AccessMode::Write->value,
            'database_connection_ids' => [$connection->id],
            'title' => 'Correct employee records',
            'access_duration_minutes' => 30,
        ]);
        $request->forceFill([
            'status' => QueryRequestStatus::Approved,
            'approved_at' => now(),
        ])->save();

        $fakeExecutor = new class(app(DatabaseTlsMaterializer::class)) extends DatabaseQueryExecutor
        {
            public function execute(DatabaseConnection $databaseConnection, string $sql, QueryType $queryType): array
            {
                return [
                    'row_count' => 1,
                    'sample_rows' => [],
                    'result_truncated' => false,
                ];
            }
        };
        $this->app->instance(DatabaseQueryExecutor::class, $fakeExecutor);

        $session = app(QuerySessionWorkflow::class)->start($request, $user);
        $result = app(QuerySessionWorkflow::class)->execute(
            $session,
            $user,
            'update employees set active = 1 where id = 1',
            $connection,
        );

        $this->assertSame(QueryType::Write, $result['query']->query_type);
    }

    public function test_active_write_session_stops_accepting_writes_when_query_access_is_restricted(): void
    {
        [$user, $connection] = $this->userWithSeparatedReadAndWritePolicies();
        $request = app(QueryRequestWorkflow::class)->create($user, [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'requested_access_mode' => AccessMode::Write->value,
            'database_connection_ids' => [$connection->id],
            'title' => 'Correct employee records',
            'access_duration_minutes' => 20,
        ]);
        $request->forceFill([
            'status' => QueryRequestStatus::Approved,
            'approved_at' => now(),
        ])->save();

        $session = app(QuerySessionWorkflow::class)->start($request, $user);
        RoleDatabasePermission::query()
            ->where('database_connection_id', $connection->id)
            ->where('access_mode', AccessMode::Write->value)
            ->update(['query_access_mode' => AccessMode::Read->value]);
        $user->refresh();

        try {
            app(QuerySessionWorkflow::class)->execute(
                $session,
                $user,
                'UPDATE employees SET active = 1 WHERE id = 1',
                $connection,
            );
            $this->fail('The session should enforce the current Query Access capability.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Your current roles are not allowed to run this query type on the selected database.',
                $exception->errors()['sql'][0],
            );
        }
    }

    public function test_query_access_renewal_rechecks_the_current_policy_for_the_selected_session_level(): void
    {
        [$user, $connection] = $this->userWithSeparatedReadAndWritePolicies();

        $readRequest = QueryRequest::factory()->queryAccess()->create([
            'requester_id' => $user->id,
            'database_connection_id' => $connection->id,
            'requested_access_mode' => AccessMode::Read,
            'status' => QueryRequestStatus::Completed,
            'completed_at' => now(),
            'access_duration_minutes' => 20,
        ]);
        $readRequest->accessConnections()->sync([$connection->id]);

        $writeRequest = QueryRequest::factory()->queryAccess()->create([
            'requester_id' => $user->id,
            'database_connection_id' => $connection->id,
            'requested_access_mode' => AccessMode::Write,
            'status' => QueryRequestStatus::Completed,
            'completed_at' => now(),
            'access_duration_minutes' => 20,
        ]);
        $writeRequest->accessConnections()->sync([$connection->id]);

        $readRenewal = app(QueryRequestWorkflow::class)->retry($readRequest, $user);
        $writeRenewal = app(QueryRequestWorkflow::class)->retry($writeRequest, $user);

        $this->assertSame(QueryRequestStatus::Approved, $readRenewal->status);
        $this->assertFalse($readRenewal->requires_approval);
        $this->assertSame(AccessMode::Read, $readRenewal->requested_access_mode);
        $this->assertSame($user->id, $readRenewal->approved_by_id);

        $this->assertSame(QueryRequestStatus::PendingReview, $writeRenewal->status);
        $this->assertTrue($writeRenewal->requires_approval);
        $this->assertSame(AccessMode::Write, $writeRenewal->requested_access_mode);
        $this->assertNull($writeRenewal->approved_by_id);
    }

    /**
     * @return array{0: User, 1: DatabaseConnection}
     */
    private function userWithSeparatedReadAndWritePolicies(): array
    {
        $readRole = Role::factory()->developer()->create(['name' => 'Database Read']);
        $writeRole = Role::factory()->create([
            'name' => 'Database Write',
            'slug' => 'database-write',
            'is_admin' => false,
        ]);
        $user = User::factory()->withRole($readRole)->create();
        $user->roles()->attach($writeRole, ['priority' => 110]);
        $user->roles()->updateExistingPivot($readRole->id, ['priority' => 100]);
        $connection = DatabaseConnection::factory()->create(['name' => 'Staging Primary']);

        RoleDatabasePermission::factory()->create([
            'role_id' => $readRole->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'read_requires_approval' => false,
            'write_requires_approval' => true,
        ]);
        RoleDatabasePermission::factory()->create([
            'role_id' => $writeRole->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Write,
            'query_access_mode' => AccessMode::Write,
            'read_requires_approval' => false,
            'write_requires_approval' => true,
            'max_write_session_minutes' => 30,
        ]);

        return [$user, $connection];
    }
}
