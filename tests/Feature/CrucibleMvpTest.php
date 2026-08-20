<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Enums\DatabaseDriver;
use App\Enums\ExecutionStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Jobs\ExecuteQueryRequest;
use App\Models\AuditLog;
use App\Models\DatabaseConnection;
use App\Models\QueryExecution;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\QuerySessionQuery;
use App\Models\Role;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DatabaseQueryExecutor;
use App\Services\DeploymentPreflight;
use App\Services\NotificationDispatcher;
use App\Services\QueryGuard;
use App\Services\QueryRequestWorkflow;
use App\Services\QuerySessionWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class CrucibleMvpTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_connection_with_encrypted_password(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->post(route('connections.store'), [
            'name' => 'Reporting',
            'driver' => DatabaseDriver::PostgreSql->value,
            'host' => 'target-postgres',
            'port' => 5432,
            'database' => 'app',
            'username' => 'app_user',
            'password' => 'secret-password',
            'ssl_mode' => 'prefer',
            'is_active' => '1',
        ]);

        $connection = DatabaseConnection::query()->firstOrFail();

        $response->assertRedirect(route('connections.show', $connection));
        $this->assertNotSame(
            'secret-password',
            DB::table('database_connections')->whereKey($connection->id)->value('password'),
        );
        $this->assertSame('secret-password', $connection->password);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'database_connection.created',
            'auditable_id' => $connection->id,
        ]);
    }

    public function test_connections_index_returns_paginated_connections(): void
    {
        $admin = $this->adminUser();
        DatabaseConnection::factory()->count(16)->create();

        $this->actingAs($admin)
            ->get(route('connections.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('connections/index')
                ->has('connections.data', 15)
                ->where('connections.current_page', 1)
                ->where('connections.last_page', 2)
                ->where('connections.total', 16)
                ->where('connection_count', 16)
                ->has('connections.links', 4));

        $this->actingAs($admin)
            ->get(route('connections.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('connections/index')
                ->has('connections.data', 1)
                ->where('connections.current_page', 2)
                ->where('connections.from', 16)
                ->where('connections.to', 16));
    }

    public function test_connections_index_filters_connections_and_preserves_filters_across_pages(): void
    {
        $admin = $this->adminUser();
        DatabaseConnection::factory()->mysql()->count(16)->create([
            'host' => 'shared-batch.internal',
            'is_active' => true,
        ]);
        DatabaseConnection::factory()->postgresql()->create([
            'host' => 'shared-batch.internal',
            'is_active' => true,
        ]);
        DatabaseConnection::factory()->mysql()->create([
            'host' => 'shared-batch.internal',
            'is_active' => false,
        ]);

        $filters = [
            'search' => 'shared-batch',
            'driver' => DatabaseDriver::MySql->value,
            'status' => 'active',
        ];

        $this->actingAs($admin)
            ->get(route('connections.index', $filters))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('connections/index')
                ->where('filters', $filters)
                ->has('connections.data', 15)
                ->where('connections.total', 16)
                ->where('connection_count', 18)
                ->where('connections.last_page', 2)
                ->where('connections.links.3.url', fn (?string $url): bool => $url !== null
                    && str_contains($url, 'search=shared-batch')
                    && str_contains($url, 'driver=mysql')
                    && str_contains($url, 'status=active')));

        $this->actingAs($admin)
            ->get(route('connections.index', [...$filters, 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('connections.data', 1)
                ->where('connections.current_page', 2));
    }

    public function test_admin_can_save_a_connection_and_continue_with_shared_server_defaults(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->post(route('connections.store'), [
            'name' => 'Production Orders',
            'driver' => DatabaseDriver::PostgreSql->value,
            'host' => 'orders.internal',
            'port' => 5432,
            'database' => 'orders',
            'username' => 'orders_user',
            'password' => 'secret-password',
            'ssl_mode' => 'require',
            'is_active' => '1',
            'create_another' => '1',
        ]);

        $createAnotherUrl = route('connections.create', [
            'driver' => DatabaseDriver::PostgreSql->value,
            'host' => 'orders.internal',
            'port' => 5432,
            'ssl_mode' => 'require',
        ]);

        $response->assertRedirect($createAnotherUrl);
        $connection = DatabaseConnection::query()->where('name', 'Production Orders')->firstOrFail();
        $this->assertModelExists($connection);
        $this->assertSame('orders.internal', $connection->host);

        $this->actingAs($admin)
            ->get($createAnotherUrl)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('connections/form')
                ->where('connection', null)
                ->where('defaults', [
                    'driver' => DatabaseDriver::PostgreSql->value,
                    'host' => 'orders.internal',
                    'port' => 5432,
                    'ssl_mode' => 'require',
                ]));
    }

    public function test_admin_sees_success_toast_when_connection_test_succeeds(): void
    {
        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->create();

        $this->app->bind(DatabaseQueryExecutor::class, fn () => new class extends DatabaseQueryExecutor
        {
            public function execute(DatabaseConnection $databaseConnection, string $sql, QueryType $queryType): array
            {
                return [
                    'row_count' => 1,
                    'sample_rows' => [
                        ['crucible_health_check' => 1],
                    ],
                ];
            }
        });

        $this->actingAs($admin)
            ->post(route('connections.test', $connection))
            ->assertRedirect()
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => [
                    'type' => 'success',
                    'message' => 'Connection test succeeded.',
                ],
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'database_connection.tested',
            'auditable_id' => $connection->id,
        ]);
    }

    public function test_admin_sees_error_toast_when_connection_test_fails(): void
    {
        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->create();

        $this->app->bind(DatabaseQueryExecutor::class, fn () => new class extends DatabaseQueryExecutor
        {
            public function execute(DatabaseConnection $databaseConnection, string $sql, QueryType $queryType): array
            {
                throw new RuntimeException('Target refused connection.');
            }
        });

        $this->actingAs($admin)
            ->post(route('connections.test', $connection))
            ->assertRedirect()
            ->assertSessionHasErrors('connection')
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => [
                    'type' => 'error',
                    'message' => 'Connection test failed. Check the connection settings and audit log.',
                ],
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'database_connection.test_failed',
            'auditable_id' => $connection->id,
        ]);
    }

    public function test_admin_can_disable_and_enable_user_while_preserving_audit_log(): void
    {
        $admin = $this->adminUser();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('users/index')
                ->has('active_users', 2)
                ->has('disabled_users', 0));

        $this->actingAs($admin)
            ->patch(route('users.disable', $user))
            ->assertRedirect()
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => [
                    'type' => 'success',
                    'message' => 'User disabled.',
                ],
            ]);

        $this->assertNotNull($user->refresh()->disabled_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.disabled',
            'auditable_id' => $user->id,
        ]);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('users/index')
                ->has('active_users', 1)
                ->has('disabled_users', 1));

        auth()->logout();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();

        $this->actingAs($admin)
            ->patch(route('users.enable', $user))
            ->assertRedirect()
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => [
                    'type' => 'success',
                    'message' => 'User enabled.',
                ],
            ]);

        $this->assertNull($user->refresh()->disabled_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.enabled',
            'auditable_id' => $user->id,
        ]);
    }

    public function test_audit_logs_can_be_exported_as_csv_with_filters(): void
    {
        $admin = $this->adminUser();

        AuditLog::query()->create([
            'actor_id' => $admin->id,
            'action' => 'database_connection.created',
            'auditable_type' => DatabaseConnection::class,
            'auditable_id' => 10,
            'ip_address' => '127.0.0.1',
            'metadata' => ['connection' => 'Reporting'],
        ]);
        AuditLog::query()->create([
            'actor_id' => null,
            'action' => 'query_session.expired',
            'auditable_type' => QuerySession::class,
            'auditable_id' => 20,
            'ip_address' => '127.0.0.1',
            'metadata' => ['session' => 20],
        ]);

        $response = $this->actingAs($admin)->get(route('audit-logs.export', [
            'action' => 'database_connection.created',
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('created_at,action,actor,auditable_type,auditable_id,ip_address,metadata', $csv);
        $this->assertStringContainsString('database_connection.created', $csv);
        $this->assertStringNotContainsString('query_session.expired', $csv);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'audit_logs.exported',
            'actor_id' => $admin->id,
        ]);
    }

    public function test_query_execution_result_can_be_exported_as_csv(): void
    {
        $admin = $this->adminUser();
        $queryRequest = QueryRequest::factory()->approved()->create([
            'requester_id' => $admin->id,
        ]);
        $execution = QueryExecution::factory()->create([
            'query_request_id' => $queryRequest->id,
            'sample_rows' => [
                ['id' => 1, 'name' => 'Jane'],
                ['id' => 2, 'name' => 'John'],
            ],
        ]);

        $response = $this->actingAs($admin)->get(route('query-executions.export', $execution));

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('id,name', $csv);
        $this->assertStringContainsString('1,Jane', $csv);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'query_execution.exported',
            'auditable_id' => $execution->id,
        ]);
    }

    public function test_query_session_query_result_can_be_exported_as_csv(): void
    {
        $admin = $this->adminUser();
        $session = QuerySession::factory()->create([
            'user_id' => $admin->id,
        ]);
        $query = QuerySessionQuery::factory()->create([
            'query_session_id' => $session->id,
            'user_id' => $admin->id,
            'sample_rows' => [
                ['value' => 1],
            ],
        ]);

        $response = $this->actingAs($admin)->get(route('query-session-queries.export', $query));

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('value', $csv);
        $this->assertStringContainsString('1', $csv);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'query_session_query.exported',
            'auditable_id' => $query->id,
        ]);
    }

    public function test_role_permission_controls_query_creation_and_approval_bypass(): void
    {
        Queue::fake();

        $developer = $this->developerUser();
        $connection = DatabaseConnection::factory()->create();

        $this->actingAs($developer)->post(route('query-requests.store'), [
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Read app data',
            'sql' => 'select 1 as value',
        ])->assertForbidden();

        RoleDatabasePermission::factory()->create([
            'role_id' => $this->roleId($developer),
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'requires_approval' => true,
        ]);

        $this->actingAs($developer)->post(route('query-requests.store'), [
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Read app data',
            'sql' => 'select 1 as value',
        ])->assertRedirect();

        $this->assertDatabaseHas('query_requests', [
            'title' => 'Read app data',
            'status' => QueryRequestStatus::PendingReview->value,
            'requires_approval' => true,
        ]);
        Queue::assertNotPushed(ExecuteQueryRequest::class);

        RoleDatabasePermission::query()->where('role_id', $this->roleId($developer))->update([
            'requires_approval' => false,
            'read_requires_approval' => false,
            'write_requires_approval' => false,
        ]);

        $this->actingAs($developer)->post(route('query-requests.store'), [
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Bypass read',
            'sql' => 'select 2 as value',
        ])->assertRedirect();

        $this->assertDatabaseHas('query_requests', [
            'title' => 'Bypass read',
            'status' => QueryRequestStatus::Approved->value,
            'requires_approval' => false,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_query_request_create_page_includes_searchable_connection_metadata(): void
    {
        $admin = $this->adminUser();
        DatabaseConnection::factory()->create([
            'name' => 'Analytics Primary',
            'driver' => DatabaseDriver::PostgreSql,
            'is_active' => true,
        ]);
        DatabaseConnection::factory()->create([
            'name' => 'Legacy Archive',
            'driver' => DatabaseDriver::MySql,
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('query-requests.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('query-requests/create')
                ->has('connections', 1)
                ->where('connections.0.name', 'Analytics Primary')
                ->where('connections.0.driver', DatabaseDriver::PostgreSql->value));
    }

    public function test_create_table_statement_is_classified_as_write_access(): void
    {
        Queue::fake();

        $developer = $this->developerUser();
        $connection = DatabaseConnection::factory()->create();

        RoleDatabasePermission::factory()->create([
            'role_id' => $this->roleId($developer),
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'requires_approval' => false,
        ]);

        $this->actingAs($developer)->post(route('query-requests.store'), [
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Create staging table',
            'sql' => 'create table staging_orders (id integer)',
        ])->assertSessionHasErrors('statements.0.database_connection_id');

        RoleDatabasePermission::query()->where('role_id', $this->roleId($developer))->update([
            'access_mode' => AccessMode::Write,
        ]);

        $this->actingAs($developer)->post(route('query-requests.store'), [
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Create staging table',
            'sql' => 'create table staging_orders (id integer)',
        ])->assertRedirect();

        $this->assertDatabaseHas('query_requests', [
            'title' => 'Create staging table',
            'query_type' => QueryType::Write->value,
            'status' => QueryRequestStatus::Approved->value,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_query_guard_preserves_line_breaks_so_line_comments_do_not_break_insert_select(): void
    {
        $sql = <<<'SQL'
INSERT INTO employee (first_name, last_name, email, department, salary, hire_date)
SELECT
    -- Cycle through an array of first names.
    (ARRAY['John', 'Jane'])[(i % 2) + 1],
    (ARRAY['Smith', 'Johnson'])[(i % 2) + 1] || i,
    'employee.' || i || '@companydummy.com',
    CASE (i % 2)
        WHEN 0 THEN 'Engineering'
        ELSE 'Finance'
    END,
    ROUND((random() * 70000 + 50000)::NUMERIC, 2),
    CURRENT_DATE - (random() * 1800)::INT
FROM generate_series(1, 100) AS i;
SQL;

        $guard = app(QueryGuard::class);
        $statement = $guard->validateExecutable($sql);

        $this->assertSame(QueryType::Write, $guard->classify($sql));
        $this->assertStringContainsString("\n    -- Cycle through an array of first names.", $statement);
        $this->assertStringNotContainsString(';', $statement);
    }

    public function test_query_request_index_filters_by_column_values(): void
    {
        $admin = $this->adminUser();
        $includedConnection = DatabaseConnection::factory()->create(['name' => 'Primary Warehouse']);
        $excludedConnection = DatabaseConnection::factory()->create(['name' => 'Archive Warehouse']);

        QueryRequest::factory()->create([
            'database_connection_id' => $includedConnection->id,
            'title' => 'Customer export',
            'status' => QueryRequestStatus::Completed,
            'query_type' => QueryType::Read,
            'request_kind' => QueryRequestKind::SingleExecution,
        ]);
        QueryRequest::factory()->create([
            'database_connection_id' => $excludedConnection->id,
            'title' => 'Customer export archive',
            'status' => QueryRequestStatus::PendingReview,
            'query_type' => QueryType::Write,
            'request_kind' => QueryRequestKind::QueryAccess,
        ]);

        $this->actingAs($admin)
            ->get(route('query-requests.index', [
                'search' => 'Customer',
                'status' => QueryRequestStatus::Completed->value,
                'request_kind' => QueryRequestKind::SingleExecution->value,
                'query_type' => QueryType::Read->value,
                'connection_id' => $includedConnection->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', QueryRequestStatus::Completed->value)
                ->where('filters.connection_id', (string) $includedConnection->id)
                ->has('query_requests.data', 1)
                ->where('query_requests.data.0.title', 'Customer export'));
    }

    public function test_query_request_lists_surface_write_when_any_execution_writes(): void
    {
        $admin = $this->adminUser();
        $queryRequest = QueryRequest::factory()->queryAccess()->create([
            'requester_id' => $admin->id,
            'query_type' => QueryType::Read,
            'requested_access_mode' => AccessMode::Write,
            'status' => QueryRequestStatus::Running,
        ]);

        QueryExecution::factory()->create([
            'query_request_id' => $queryRequest->id,
            'executed_by_id' => $admin->id,
            'query_type' => QueryType::Write,
            'status' => ExecutionStatus::Succeeded,
            'started_at' => now()->subMinutes(2),
        ]);
        QueryExecution::factory()->create([
            'query_request_id' => $queryRequest->id,
            'executed_by_id' => $admin->id,
            'query_type' => QueryType::Read,
            'status' => ExecutionStatus::Succeeded,
            'started_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin)
            ->get(route('query-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('query_requests.data.0.latest_query_type', QueryType::Read->value)
                ->where('query_requests.data.0.effective_query_type', QueryType::Write->value)
                ->where('query_requests.data.0.requested_access_mode', AccessMode::Write->value));

    }

    public function test_non_admin_user_can_view_requests_for_accessible_connections(): void
    {
        $viewer = $this->developerUser('Viewer');
        $requester = User::factory()->create();
        $accessibleConnection = DatabaseConnection::factory()->create(['name' => 'Shared Warehouse']);
        $hiddenConnection = DatabaseConnection::factory()->create(['name' => 'Private Warehouse']);

        RoleDatabasePermission::factory()->create([
            'role_id' => $this->roleId($viewer),
            'database_connection_id' => $accessibleConnection->id,
            'access_mode' => AccessMode::Read,
            'can_review' => false,
        ]);

        $visibleRequest = QueryRequest::factory()->create([
            'requester_id' => $requester->id,
            'database_connection_id' => $accessibleConnection->id,
            'title' => 'Shared request',
        ]);
        QueryRequest::factory()->create([
            'requester_id' => $requester->id,
            'database_connection_id' => $hiddenConnection->id,
            'title' => 'Hidden request',
        ]);

        $this->actingAs($viewer)
            ->get(route('query-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('query_requests.data', 1)
                ->where('query_requests.data.0.title', 'Shared request'));

        $this->actingAs($viewer)
            ->get(route('query-requests.show', $visibleRequest))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('query_request.title', 'Shared request')
                ->where('can_review', false)
                ->where('can_start_session', false));
    }

    public function test_query_access_request_show_includes_the_latest_ended_session(): void
    {
        $developer = $this->developerUser();
        $connection = DatabaseConnection::factory()->create();
        $endedAt = now()->subMinute()->startOfSecond();

        RoleDatabasePermission::factory()->create([
            'role_id' => $this->roleId($developer),
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'requires_approval' => false,
        ]);

        $queryRequest = QueryRequest::factory()->queryAccess()->create([
            'requester_id' => $developer->id,
            'database_connection_id' => $connection->id,
            'status' => QueryRequestStatus::Completed,
            'completed_at' => $endedAt,
        ]);
        $queryRequest->accessConnections()->sync([$connection->id]);

        QuerySession::factory()->create([
            'query_request_id' => $queryRequest->id,
            'user_id' => $developer->id,
            'database_connection_id' => $connection->id,
            'started_at' => $endedAt->copy()->subMinutes(4),
            'expires_at' => $endedAt->copy()->addMinutes(5),
            'ended_at' => $endedAt,
        ]);

        $this->actingAs($developer)
            ->get(route('query-requests.show', $queryRequest))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('query_request.status', QueryRequestStatus::Completed->value)
                ->where('query_request.active_session', null)
                ->has('query_request.sessions', 1)
                ->where('query_request.sessions.0.ended_at', $endedAt->toIso8601String()));
    }

    public function test_audit_log_index_filters_by_action_actor_ip_and_search(): void
    {
        $admin = $this->adminUser();
        $otherUser = User::factory()->create([
            'name' => 'Other Operator',
            'email' => 'other@example.test',
        ]);

        AuditLog::factory()->create([
            'actor_id' => $admin->id,
            'action' => 'query_session.query_executed',
            'auditable_type' => QuerySession::class,
            'auditable_id' => 10,
            'ip_address' => '10.10.0.5',
            'metadata' => [
                'query_request_id' => 42,
                'sql' => 'select * from warehouse_orders',
            ],
        ]);
        AuditLog::factory()->create([
            'actor_id' => $otherUser->id,
            'action' => 'database_connection.created',
            'auditable_type' => DatabaseConnection::class,
            'auditable_id' => 11,
            'ip_address' => '10.10.0.6',
            'metadata' => [
                'name' => 'Archive',
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('audit-logs.index', [
                'search' => 'warehouse_orders',
                'action' => 'query_session.query_executed',
                'actor' => $admin->name,
                'ip_address' => '10.10.0.5',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', 'warehouse_orders')
                ->where('filters.action', 'query_session.query_executed')
                ->where('filters.actor', $admin->name)
                ->where('filters.ip_address', '10.10.0.5')
                ->has('audit_logs.data', 1)
                ->where('audit_logs.data.0.action', 'query_session.query_executed')
                ->where('audit_logs.data.0.actor', $admin->name));
    }

    public function test_reviewer_can_approve_pending_query_then_requester_dispatches_job(): void
    {
        Queue::fake();

        $developer = $this->developerUser();
        $reviewer = $this->developerUser('Reviewer');
        $connection = DatabaseConnection::factory()->create();

        RoleDatabasePermission::factory()->create([
            'role_id' => $this->roleId($developer),
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'requires_approval' => true,
        ]);
        RoleDatabasePermission::factory()->create([
            'role_id' => $this->roleId($reviewer),
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'can_review' => true,
            'requires_approval' => false,
        ]);

        $queryRequest = app(QueryRequestWorkflow::class)->create($developer, $connection, [
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Needs review',
            'sql' => 'select 1 as value',
        ]);

        $this->actingAs($reviewer)->post(route('query-requests.reviews.store', $queryRequest), [
            'decision' => 'approved',
            'comment' => 'Looks good.',
        ])->assertRedirect();

        $queryRequest->refresh();

        $this->assertSame(QueryRequestStatus::Approved, $queryRequest->status);
        $this->assertSame($reviewer->id, $queryRequest->approved_by_id);
        Queue::assertNotPushed(ExecuteQueryRequest::class);
        $this->assertDatabaseHas('query_reviews', [
            'query_request_id' => $queryRequest->id,
            'reviewer_id' => $reviewer->id,
            'decision' => 'approved',
        ]);

        $this->actingAs($developer)->post(route('query-requests.dispatch', $queryRequest))
            ->assertRedirect()
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => [
                    'type' => 'success',
                    'message' => 'Deployment batch queued for execution.',
                ],
            ]);

        $this->assertSame($developer->id, $queryRequest->refresh()->dispatched_by_id);
        Queue::assertPushed(ExecuteQueryRequest::class, 1);

        $this->actingAs($developer)->post(route('query-requests.dispatch', $queryRequest))
            ->assertForbidden();

        Queue::assertPushed(ExecuteQueryRequest::class, 1);
    }

    public function test_multiple_roles_use_highest_priority_database_policy(): void
    {
        Queue::fake();

        $developer = $this->developerUser();
        $developerRoleId = $this->roleId($developer);
        $connection = DatabaseConnection::factory()->create();
        $bypassRole = Role::factory()->developer()->create([
            'name' => 'Trusted Executor',
            'slug' => 'trusted-executor',
        ]);

        $developer->roles()->updateExistingPivot($developerRoleId, ['priority' => 10]);
        $developer->roles()->attach($bypassRole, ['priority' => 20]);

        RoleDatabasePermission::factory()->create([
            'role_id' => $developerRoleId,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'requires_approval' => true,
        ]);
        RoleDatabasePermission::factory()->create([
            'role_id' => $bypassRole->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'requires_approval' => false,
        ]);

        $this->actingAs($developer)->post(route('query-requests.store'), [
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Priority requires approval',
            'sql' => 'select 3 as value',
        ])->assertRedirect();

        $this->assertDatabaseHas('query_requests', [
            'title' => 'Priority requires approval',
            'status' => QueryRequestStatus::PendingReview->value,
            'requires_approval' => true,
        ]);
        Queue::assertNothingPushed();

        $developer->roles()->updateExistingPivot($bypassRole->id, ['priority' => 5]);

        $this->actingAs($developer)->post(route('query-requests.store'), [
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Priority bypasses approval',
            'sql' => 'select 4 as value',
        ])->assertRedirect();

        $this->assertDatabaseHas('query_requests', [
            'title' => 'Priority bypasses approval',
            'status' => QueryRequestStatus::Approved->value,
            'requires_approval' => false,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_review_authority_uses_highest_priority_database_policy(): void
    {
        Queue::fake();

        $developer = $this->developerUser();
        $reviewer = $this->developerUser('Reviewer');
        $reviewerPrimaryRoleId = $this->roleId($reviewer);
        $reviewerBackupRole = Role::factory()->developer()->create([
            'name' => 'Backup Reviewer',
            'slug' => 'backup-reviewer',
        ]);
        $connection = DatabaseConnection::factory()->create();

        $reviewer->roles()->updateExistingPivot($reviewerPrimaryRoleId, ['priority' => 10]);
        $reviewer->roles()->attach($reviewerBackupRole, ['priority' => 20]);

        RoleDatabasePermission::factory()->create([
            'role_id' => $this->roleId($developer),
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'requires_approval' => true,
        ]);
        RoleDatabasePermission::factory()->create([
            'role_id' => $reviewerPrimaryRoleId,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'can_review' => false,
            'requires_approval' => true,
        ]);
        RoleDatabasePermission::factory()->create([
            'role_id' => $reviewerBackupRole->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'can_review' => true,
            'requires_approval' => true,
        ]);

        $queryRequest = app(QueryRequestWorkflow::class)->create($developer, $connection, [
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Priority review',
            'sql' => 'select 1 as value',
        ]);

        $this->actingAs($reviewer)->post(route('query-requests.reviews.store', $queryRequest), [
            'decision' => 'approved',
        ])->assertForbidden();

        $reviewer->roles()->updateExistingPivot($reviewerBackupRole->id, ['priority' => 5]);

        $this->actingAs($reviewer)->post(route('query-requests.reviews.store', $queryRequest), [
            'decision' => 'approved',
        ])->assertRedirect();

        $this->assertDatabaseHas('query_reviews', [
            'query_request_id' => $queryRequest->id,
            'reviewer_id' => $reviewer->id,
            'decision' => 'approved',
        ]);
    }

    public function test_due_scheduled_query_requests_are_dispatched(): void
    {
        Queue::fake();
        $admin = $this->adminUser();

        $queryRequest = QueryRequest::factory()->scheduled()->create([
            'requester_id' => $admin->id,
            'scheduled_at' => Carbon::now()->subMinute(),
            'status' => QueryRequestStatus::Scheduled,
        ]);

        $this->artisan('crucible:dispatch-due-query-requests')->assertSuccessful();

        $this->assertSame(QueryRequestStatus::Approved, $queryRequest->refresh()->status);
        $this->assertNotNull($queryRequest->dispatched_at);
        Queue::assertPushed(ExecuteQueryRequest::class);
    }

    public function test_late_approval_keeps_a_scheduled_deployment_batch_ready_for_an_explicit_run(): void
    {
        Queue::fake();

        $requester = $this->developerUser();
        $reviewer = $this->developerUser('Reviewer');
        $connection = DatabaseConnection::factory()->create();

        RoleDatabasePermission::factory()->create([
            'role_id' => $this->roleId($requester),
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'requires_approval' => true,
        ]);
        RoleDatabasePermission::factory()->create([
            'role_id' => $this->roleId($reviewer),
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'can_review' => true,
            'requires_approval' => false,
        ]);

        $queryRequest = app(QueryRequestWorkflow::class)->create($requester, $connection, [
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Deploy after delayed approval',
            'sql' => 'select 1 as value',
            'scheduled_at' => now()->subHour()->toIso8601String(),
        ]);

        $this->actingAs($reviewer)->post(route('query-requests.reviews.store', $queryRequest), [
            'decision' => 'approved',
        ])->assertRedirect();

        $queryRequest->refresh();

        $this->assertSame(QueryRequestStatus::Approved, $queryRequest->status);
        $this->assertNull($queryRequest->dispatched_at);
        Queue::assertNotPushed(ExecuteQueryRequest::class);

        $this->actingAs($requester)
            ->get(route('query-requests.show', $queryRequest))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('query-requests/show')
                ->where('query_request.approved_after_schedule', true));

        $this->artisan('crucible:dispatch-due-query-requests')->assertSuccessful();
        Queue::assertNotPushed(ExecuteQueryRequest::class);

        $this->actingAs($requester)
            ->post(route('query-requests.dispatch', $queryRequest))
            ->assertRedirect()
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => [
                    'type' => 'success',
                    'message' => 'Deployment batch queued for execution.',
                ],
            ]);

        Queue::assertPushed(ExecuteQueryRequest::class, 1);
    }

    public function test_query_access_request_starts_time_boxed_session_and_records_session_query(): void
    {
        $developer = $this->developerUser();
        $connection = DatabaseConnection::factory()->create();

        RoleDatabasePermission::factory()->create([
            'role_id' => $this->roleId($developer),
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'requires_approval' => false,
        ]);

        $this->app->bind(DatabaseQueryExecutor::class, fn () => new class extends DatabaseQueryExecutor
        {
            public function execute(DatabaseConnection $connection, string $sql, QueryType $queryType): array
            {
                return [
                    'row_count' => 1000,
                    'result_truncated' => true,
                    'sample_rows' => [
                        ['value' => 1],
                    ],
                ];
            }
        });

        $queryRequest = app(QueryRequestWorkflow::class)->create($developer, $connection, [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'title' => 'Open browser access',
            'description' => 'Investigate data issue.',
            'access_duration_minutes' => 30,
        ]);

        $this->assertSame(QueryRequestStatus::Approved, $queryRequest->status);
        $this->assertSame(QueryRequestKind::QueryAccess, $queryRequest->request_kind);
        $this->assertSame(30, $queryRequest->access_duration_minutes);

        $session = app(QuerySessionWorkflow::class)->start($queryRequest, $developer);

        $this->assertTrue($session->isActive());
        $this->assertSame(QueryRequestStatus::Running, $queryRequest->refresh()->status);

        app(QuerySessionWorkflow::class)->execute($session->load('databaseConnection'), $developer, 'select 1 as value');

        $this->assertDatabaseHas('query_session_queries', [
            'query_session_id' => $session->id,
            'status' => ExecutionStatus::Succeeded->value,
            'row_count' => 1000,
            'result_truncated' => 1,
        ]);
        $this->assertDatabaseHas('query_executions', [
            'query_request_id' => $queryRequest->id,
            'executed_by_id' => $developer->id,
            'sql' => 'select 1 as value',
            'query_type' => QueryType::Read->value,
            'status' => ExecutionStatus::Succeeded->value,
            'row_count' => 1000,
            'result_truncated' => 1,
        ]);
        $this->assertTrue(AuditLog::query()->where('action', 'query_session.query_executed')->exists());

        $this->actingAs($developer)
            ->get(route('query-requests.show', $queryRequest))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('query_request.created_at', $queryRequest->created_at->toIso8601String())
                ->where('query_request.active_session.id', $session->id)
                ->has('query_request.executions.data', 1)
                ->where('query_request.executions.data.0.sql', 'select 1 as value')
                ->where('query_request.executions.data.0.result_truncated', true));
    }

    public function test_query_access_session_query_failure_is_recorded_without_exception_page(): void
    {
        $developer = $this->developerUser();
        $connection = DatabaseConnection::factory()->create();

        RoleDatabasePermission::factory()->create([
            'role_id' => $this->roleId($developer),
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'requires_approval' => false,
        ]);

        $this->app->bind(DatabaseQueryExecutor::class, fn () => new class extends DatabaseQueryExecutor
        {
            public function execute(DatabaseConnection $connection, string $sql, QueryType $queryType): array
            {
                throw new RuntimeException('syntax error at or near "limi"');
            }
        });

        $queryRequest = app(QueryRequestWorkflow::class)->create($developer, $connection, [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'title' => 'Open browser access',
            'access_duration_minutes' => 30,
        ]);
        $session = app(QuerySessionWorkflow::class)->start($queryRequest, $developer);

        $this->actingAs($developer)
            ->post(route('query-sessions.queries.store', $session), [
                'sql' => 'select * from bulk_activity_log limi 49',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('sql')
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => [
                    'type' => 'error',
                    'message' => 'Query failed. Check the result panel for details.',
                ],
            ]);

        $this->assertDatabaseHas('query_session_queries', [
            'query_session_id' => $session->id,
            'status' => ExecutionStatus::Failed->value,
            'error_message' => 'syntax error at or near "limi"',
        ]);
        $this->assertDatabaseHas('query_executions', [
            'query_request_id' => $queryRequest->id,
            'executed_by_id' => $developer->id,
            'sql' => 'select * from bulk_activity_log limi 49',
            'query_type' => QueryType::Read->value,
            'status' => ExecutionStatus::Failed->value,
            'error_message' => 'syntax error at or near "limi"',
        ]);
        $this->assertTrue(AuditLog::query()->where('action', 'query_session.query_failed')->exists());
    }

    public function test_expired_query_access_session_submission_returns_validation_error_not_forbidden(): void
    {
        $developer = $this->developerUser();
        $connection = DatabaseConnection::factory()->create();

        RoleDatabasePermission::factory()->create([
            'role_id' => $this->roleId($developer),
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'requires_approval' => false,
        ]);

        $queryRequest = app(QueryRequestWorkflow::class)->create($developer, $connection, [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'title' => 'Expired browser access',
            'access_duration_minutes' => 1,
        ]);
        $queryRequest->forceFill([
            'status' => QueryRequestStatus::Running,
            'dispatched_at' => now()->subHour(),
        ])->save();
        $session = QuerySession::factory()->create([
            'query_request_id' => $queryRequest->id,
            'user_id' => $developer->id,
            'database_connection_id' => $connection->id,
            'started_at' => now()->subHour(),
            'expires_at' => now()->subMinute(),
            'ended_at' => null,
        ]);

        $this->actingAs($developer)
            ->post(route('query-sessions.queries.store', $session), [
                'sql' => 'select 1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors([
                'sql' => 'This query session is not active.',
            ])
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => [
                    'type' => 'error',
                    'message' => 'This query session is not active.',
                ],
            ]);
    }

    public function test_admin_can_delete_query_access_request_with_audit_log(): void
    {
        $admin = $this->adminUser();
        $queryRequest = QueryRequest::factory()->queryAccess()->approved()->create([
            'title' => 'Temporary browser access',
        ]);
        QuerySession::factory()->create([
            'query_request_id' => $queryRequest->id,
            'user_id' => $queryRequest->requester_id,
            'database_connection_id' => $queryRequest->database_connection_id,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->actingAs($admin)
            ->delete(route('query-requests.destroy', $queryRequest))
            ->assertRedirect(route('query-requests.index'))
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => [
                    'type' => 'success',
                    'message' => 'Query access request deleted.',
                ],
            ]);

        $this->assertModelMissing($queryRequest);

        $auditLog = AuditLog::query()
            ->where('action', 'query_request.deleted')
            ->firstOrFail();

        $this->assertSame($queryRequest->id, $auditLog->metadata['query_request_id']);
        $this->assertSame('Temporary browser access', $auditLog->metadata['title']);
        $this->assertSame(QueryRequestKind::QueryAccess->value, $auditLog->metadata['request_kind']);
        $this->assertSame(1, $auditLog->metadata['session_count']);
    }

    public function test_expired_query_access_sessions_are_completed_by_scheduler_command(): void
    {
        $queryRequest = QueryRequest::factory()->queryAccess()->create([
            'status' => QueryRequestStatus::Running,
        ]);
        $session = QuerySession::factory()->create([
            'query_request_id' => $queryRequest->id,
            'user_id' => $queryRequest->requester_id,
            'database_connection_id' => $queryRequest->database_connection_id,
            'started_at' => now()->subHour(),
            'expires_at' => now()->subMinute(),
            'ended_at' => null,
        ]);

        $this->artisan('crucible:expire-query-sessions')->assertSuccessful();

        $this->assertNotNull($session->refresh()->ended_at);
        $this->assertSame(QueryRequestStatus::Completed, $queryRequest->refresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'query_session.expired')->exists());
    }

    public function test_execution_job_records_result_summary_and_audit_log(): void
    {
        $admin = $this->adminUser();
        $this->app->bind(DatabaseQueryExecutor::class, fn () => new class extends DatabaseQueryExecutor
        {
            public function execute(DatabaseConnection $connection, string $sql, QueryType $queryType): array
            {
                return [
                    'row_count' => 1,
                    'sample_rows' => [
                        ['value' => 1],
                    ],
                ];
            }
        });

        $queryRequest = QueryRequest::factory()->approved()->create([
            'requester_id' => $admin->id,
            'sql' => 'select 1 as value',
            'query_type' => QueryType::Read,
        ]);

        (new ExecuteQueryRequest($queryRequest->id))->handle(
            app(DatabaseQueryExecutor::class),
            app(AuditLogger::class),
            app(DeploymentPreflight::class),
            app(NotificationDispatcher::class),
        );

        $queryRequest->refresh();

        $this->assertSame(QueryRequestStatus::Completed, $queryRequest->status);
        $this->assertSame(1, $queryRequest->result_summary['row_count']);
        $this->assertDatabaseHas('query_executions', [
            'query_request_id' => $queryRequest->id,
            'executed_by_id' => $queryRequest->requester_id,
            'sql' => 'select 1 as value',
            'query_type' => QueryType::Read->value,
            'status' => ExecutionStatus::Succeeded->value,
            'row_count' => 1,
        ]);
        $this->assertTrue(AuditLog::query()->where('action', 'query_request.executed')->exists());
    }

    private function adminUser(): User
    {
        $role = Role::factory()->admin()->create();

        return User::factory()->withRole($role)->create();
    }

    private function developerUser(string $name = 'Developer'): User
    {
        $role = Role::factory()->developer()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ]);

        return User::factory()->withRole($role)->create();
    }

    private function roleId(User $user): int
    {
        return $user->roles()->firstOrFail()->id;
    }
}
