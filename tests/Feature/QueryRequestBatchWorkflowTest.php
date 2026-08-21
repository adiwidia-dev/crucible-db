<?php

namespace Tests\Feature;

use App\Enums\ExecutionStatus;
use App\Enums\PreflightStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Jobs\ExecuteQueryRequest;
use App\Models\AuditLog;
use App\Models\DatabaseConnection;
use App\Models\QueryExecution;
use App\Models\QueryRequest;
use App\Models\QueryRequestStatement;
use App\Models\QuerySession;
use App\Models\Role;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use App\Notifications\OperationalNotification;
use App\Services\ApplicationSettings;
use App\Services\AuditLogger;
use App\Services\DatabaseQueryExecutor;
use App\Services\DeploymentPreflight;
use App\Services\NotificationDispatcher;
use App\Services\QueryRequestWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class QueryRequestBatchWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_execution_request_can_store_an_ordered_statement_batch(): void
    {
        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->create();

        $response = $this->actingAs($admin)->post(route('query-requests.store'), [
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'DEP-204 customer migration',
            'statements' => [
                ['sql' => 'select 1 as value'],
                ['sql' => 'update customers set active = 1 where id = 42'],
            ],
        ]);

        $queryRequest = QueryRequest::query()->firstOrFail();

        $response->assertRedirect(route('query-requests.show', $queryRequest));
        $this->assertSame(QueryType::Write, $queryRequest->query_type);
        $this->assertSame(QueryRequestStatus::Approved, $queryRequest->status);
        $this->assertSame(
            [
                'select 1 as value',
                'update customers set active = 1 where id = 42',
            ],
            $queryRequest->statements()->pluck('sql')->all(),
        );
        $this->assertSame([1, 2], $queryRequest->statements()->pluck('position')->all());
    }

    public function test_deployment_batch_assigns_each_statement_to_its_target_and_requires_approval_when_any_target_requires_it(): void
    {
        $role = Role::factory()->developer()->create();
        $requester = User::factory()->withRole($role)->create();
        $firstConnection = DatabaseConnection::factory()->create();
        $secondConnection = DatabaseConnection::factory()->create();
        RoleDatabasePermission::factory()->write()->bypassApproval()->create([
            'role_id' => $role->id,
            'database_connection_id' => $firstConnection->id,
        ]);
        RoleDatabasePermission::factory()->write()->create([
            'role_id' => $role->id,
            'database_connection_id' => $secondConnection->id,
            'requires_approval' => true,
        ]);

        $this->actingAs($requester)->post(route('query-requests.store'), [
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'DEP-301 cross-database migration',
            'statements' => [
                [
                    'database_connection_id' => $firstConnection->id,
                    'sql' => 'select 1 as value',
                ],
                [
                    'database_connection_id' => $secondConnection->id,
                    'sql' => 'update customers set active = 1 where id = 42',
                ],
            ],
        ]);

        $queryRequest = QueryRequest::query()->firstOrFail();

        $this->assertSame($firstConnection->id, $queryRequest->database_connection_id);
        $this->assertSame(QueryRequestStatus::PendingReview, $queryRequest->status);
        $this->assertTrue($queryRequest->requires_approval);
        $this->assertSame(
            [$firstConnection->id, $secondConnection->id],
            $queryRequest->statements()->pluck('database_connection_id')->all(),
        );
    }

    public function test_invalid_statement_reports_its_batch_position(): void
    {
        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->create();

        $this->actingAs($admin)->post(route('query-requests.store'), [
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Unsafe batch',
            'statements' => [
                ['sql' => 'select 1'],
                ['sql' => 'drop table customers'],
            ],
        ])->assertSessionHasErrors('statements.1.sql');

        $this->assertDatabaseCount('query_requests', 0);
    }

    public function test_deployment_batch_persists_a_per_statement_preflight_report(): void
    {
        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->create();

        $this->actingAs($admin)->post(route('query-requests.store'), [
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Read customer records',
            'statements' => [
                [
                    'database_connection_id' => $connection->id,
                    'sql' => 'select * from customers',
                ],
                [
                    'database_connection_id' => $connection->id,
                    'sql' => 'update customers set active = 1 where id = 42',
                ],
            ],
        ]);

        $queryRequest = QueryRequest::query()->firstOrFail();

        $this->assertSame(PreflightStatus::PassedWithWarnings, $queryRequest->preflight_status);
        $this->assertNotNull($queryRequest->preflight_checked_at);
        $this->assertSame(1, $queryRequest->preflight_report['summary']['warning_count']);
        $this->assertSame('warning', $queryRequest->preflight_report['statements'][0]['status']);
        $this->assertSame('passed', $queryRequest->preflight_report['statements'][1]['status']);
    }

    public function test_emergency_sql_fallback_warns_and_audits_an_unsupported_deployment_statement(): void
    {
        app(ApplicationSettings::class)->put([
            ApplicationSettings::SqlEmergencyFallbackEnabled => true,
        ]);

        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->create();

        $this->actingAs($admin)->post(route('query-requests.store'), [
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Urgent index creation',
            'statements' => [[
                'database_connection_id' => $connection->id,
                'sql' => 'CREATE INDEX customers_email_index ON customers (email)',
            ]],
        ])->assertSessionHasNoErrors();

        $queryRequest = QueryRequest::query()->firstOrFail();

        $this->assertSame(QueryType::Write, $queryRequest->query_type);
        $this->assertSame(PreflightStatus::PassedWithWarnings, $queryRequest->preflight_status);
        $this->assertSame(
            'emergency_sql_fallback',
            $queryRequest->preflight_report['statements'][0]['messages'][0]['code'],
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'query_request.emergency_sql_fallback_used',
            'auditable_id' => $queryRequest->id,
        ]);
    }

    public function test_execution_guard_blocks_a_batch_when_its_target_becomes_inactive_after_approval(): void
    {
        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->create();
        $queryRequest = QueryRequest::factory()->approved()->create([
            'requester_id' => $admin->id,
            'database_connection_id' => $connection->id,
            'sql' => 'select 1',
        ]);
        QueryRequestStatement::factory()->create([
            'query_request_id' => $queryRequest->id,
            'database_connection_id' => $connection->id,
            'position' => 1,
            'sql' => 'select 1',
            'query_type' => QueryType::Read,
        ]);
        $connection->update(['is_active' => false]);

        $fakeExecutor = new class extends DatabaseQueryExecutor
        {
            public function execute(DatabaseConnection $databaseConnection, string $sql, QueryType $queryType): array
            {
                $this->fail('The executor must not run a blocked batch.');
            }

            private function fail(string $message): never
            {
                throw new RuntimeException($message);
            }
        };

        (new ExecuteQueryRequest($queryRequest->id))->handle(
            $fakeExecutor,
            app(AuditLogger::class),
            app(DeploymentPreflight::class),
            app(NotificationDispatcher::class),
        );

        $queryRequest->refresh();

        $this->assertSame(QueryRequestStatus::Approved, $queryRequest->status);
        $this->assertSame(PreflightStatus::Blocked, $queryRequest->preflight_status);
        $this->assertSame(0, $queryRequest->executions()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'query_request.preflight_blocked',
            'auditable_id' => $queryRequest->id,
        ]);
    }

    public function test_scheduled_batch_notifies_requester_when_preflight_blocks_execution(): void
    {
        Notification::fake();

        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->create(['is_active' => false]);
        $queryRequest = QueryRequest::factory()->create([
            'requester_id' => $admin->id,
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::SingleExecution,
            'status' => QueryRequestStatus::Scheduled,
            'scheduled_at' => Carbon::now()->subMinute(),
            'sql' => 'select 1',
        ]);
        QueryRequestStatement::factory()->create([
            'query_request_id' => $queryRequest->id,
            'database_connection_id' => $connection->id,
            'position' => 1,
            'sql' => 'select 1',
            'query_type' => QueryType::Read,
        ]);

        $dispatched = app(QueryRequestWorkflow::class)->dispatch($queryRequest, $admin);

        $this->assertFalse($dispatched);
        $this->assertSame(QueryRequestStatus::Approved, $queryRequest->refresh()->status);
        $this->assertSame(PreflightStatus::Blocked, $queryRequest->preflight_status);
        Notification::assertSentTo(
            $admin,
            OperationalNotification::class,
            fn (OperationalNotification $notification): bool => $notification->toArray($admin)['event'] === 'query_request.preflight_blocked',
        );
    }

    public function test_editing_an_approved_request_replaces_statements_and_requires_reapproval(): void
    {
        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->create();
        $queryRequest = QueryRequest::factory()->approved()->create([
            'requester_id' => $admin->id,
            'database_connection_id' => $connection->id,
            'approved_by_id' => $admin->id,
            'requires_approval' => false,
            'sql' => 'select 1',
        ]);
        QueryRequestStatement::factory()->create([
            'query_request_id' => $queryRequest->id,
            'sql' => 'select 1',
        ]);

        $response = $this->actingAs($admin)->put(route('query-requests.update', $queryRequest), [
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'DEP-204 revised migration',
            'statements' => [
                ['sql' => 'select 2 as value'],
                ['sql' => 'delete from temporary_rows where id = 9'],
            ],
        ]);

        $response->assertRedirect(route('query-requests.show', $queryRequest));

        $queryRequest->refresh();

        $this->assertSame(QueryRequestStatus::PendingReview, $queryRequest->status);
        $this->assertTrue($queryRequest->requires_approval);
        $this->assertNull($queryRequest->approved_by_id);
        $this->assertNull($queryRequest->approved_at);
        $this->assertSame('DEP-204 revised migration', $queryRequest->title);
        $this->assertSame(
            ['select 2 as value', 'delete from temporary_rows where id = 9'],
            $queryRequest->statements()->pluck('sql')->all(),
        );

        $auditLog = AuditLog::query()->where('action', 'query_request.updated')->firstOrFail();
        $this->assertTrue($auditLog->metadata['approval_invalidated']);
        $this->assertSame(QueryRequestStatus::Approved->value, $auditLog->metadata['previous_status']);
    }

    public function test_dispatched_or_completed_request_cannot_be_edited(): void
    {
        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->create();
        $queryRequest = QueryRequest::factory()->create([
            'requester_id' => $admin->id,
            'database_connection_id' => $connection->id,
            'status' => QueryRequestStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->actingAs($admin)->put(route('query-requests.update', $queryRequest), [
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::SingleExecution->value,
            'title' => 'Too late',
            'statements' => [['sql' => 'select 2']],
        ])->assertForbidden();
    }

    public function test_batch_job_executes_every_statement_in_order_and_records_each_result(): void
    {
        $admin = $this->adminUser();
        $fakeExecutor = new class extends DatabaseQueryExecutor
        {
            /** @var array<int, string> */
            public array $executedSql = [];

            public function execute(DatabaseConnection $databaseConnection, string $sql, QueryType $queryType): array
            {
                $this->executedSql[] = $sql;

                return [
                    'row_count' => 1,
                    'sample_rows' => [['sql' => $sql]],
                    'result_truncated' => false,
                ];
            }
        };
        $this->app->instance(DatabaseQueryExecutor::class, $fakeExecutor);

        $queryRequest = QueryRequest::factory()->approved()->create(['requester_id' => $admin->id]);
        $this->createStatements($queryRequest);

        (new ExecuteQueryRequest($queryRequest->id))->handle(
            $fakeExecutor,
            app(AuditLogger::class),
            app(DeploymentPreflight::class),
            app(NotificationDispatcher::class),
        );

        $this->assertSame(['select 1', 'update widgets set active = 1', 'select 3'], $fakeExecutor->executedSql);
        $this->assertSame(QueryRequestStatus::Completed, $queryRequest->refresh()->status);
        $this->assertSame(3, $queryRequest->result_summary['statement_count']);
        $this->assertSame(3, $queryRequest->result_summary['row_count']);
        $this->assertSame(
            [ExecutionStatus::Succeeded, ExecutionStatus::Succeeded, ExecutionStatus::Succeeded],
            $queryRequest->executions()->orderBy('id')->pluck('status')->all(),
        );
        $this->assertSame(
            $queryRequest->statements()->pluck('id')->all(),
            $queryRequest->executions()->orderBy('id')->pluck('query_request_statement_id')->all(),
        );
        $this->assertSame(3, AuditLog::query()->where('action', 'query_request.statement_executed')->count());
    }

    public function test_batch_job_stops_after_the_first_failed_statement(): void
    {
        $admin = $this->adminUser();
        $fakeExecutor = new class extends DatabaseQueryExecutor
        {
            /** @var array<int, string> */
            public array $executedSql = [];

            public function execute(DatabaseConnection $databaseConnection, string $sql, QueryType $queryType): array
            {
                $this->executedSql[] = $sql;

                if ($sql === 'update widgets set active = 1') {
                    throw new RuntimeException('Database rejected statement 2.');
                }

                return [
                    'row_count' => 1,
                    'sample_rows' => [],
                    'result_truncated' => false,
                ];
            }
        };
        $queryRequest = QueryRequest::factory()->approved()->create(['requester_id' => $admin->id]);
        $this->createStatements($queryRequest);

        try {
            (new ExecuteQueryRequest($queryRequest->id))->handle(
                $fakeExecutor,
                app(AuditLogger::class),
                app(DeploymentPreflight::class),
                app(NotificationDispatcher::class),
            );

            $this->fail('The failing statement should stop the batch.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Database rejected statement 2.', $exception->getMessage());
        }

        $this->assertSame(['select 1', 'update widgets set active = 1'], $fakeExecutor->executedSql);
        $this->assertSame(QueryRequestStatus::Failed, $queryRequest->refresh()->status);
        $this->assertSame(2, $queryRequest->result_summary['failed_statement_position']);
        $this->assertSame(
            [ExecutionStatus::Succeeded, ExecutionStatus::Failed],
            $queryRequest->executions()->orderBy('id')->pluck('status')->all(),
        );
    }

    public function test_batch_job_executes_each_statement_on_its_selected_connection(): void
    {
        $admin = $this->adminUser();
        $fakeExecutor = new class extends DatabaseQueryExecutor
        {
            /** @var array<int, int> */
            public array $executedConnectionIds = [];

            public function execute(DatabaseConnection $databaseConnection, string $sql, QueryType $queryType): array
            {
                $this->executedConnectionIds[] = $databaseConnection->id;

                return [
                    'row_count' => 1,
                    'sample_rows' => [],
                    'result_truncated' => false,
                ];
            }
        };
        $this->app->instance(DatabaseQueryExecutor::class, $fakeExecutor);

        $firstConnection = DatabaseConnection::factory()->create();
        $secondConnection = DatabaseConnection::factory()->create();
        $queryRequest = QueryRequest::factory()->approved()->create([
            'requester_id' => $admin->id,
            'database_connection_id' => $firstConnection->id,
        ]);
        QueryRequestStatement::factory()->create([
            'query_request_id' => $queryRequest->id,
            'database_connection_id' => $firstConnection->id,
            'position' => 1,
        ]);
        QueryRequestStatement::factory()->create([
            'query_request_id' => $queryRequest->id,
            'database_connection_id' => $secondConnection->id,
            'position' => 2,
        ]);

        (new ExecuteQueryRequest($queryRequest->id))->handle(
            $fakeExecutor,
            app(AuditLogger::class),
            app(DeploymentPreflight::class),
            app(NotificationDispatcher::class),
        );

        $this->assertSame(
            [$firstConnection->id, $secondConnection->id],
            $fakeExecutor->executedConnectionIds,
        );
        $this->assertSame(
            [$firstConnection->id, $secondConnection->id],
            $queryRequest->executions()
                ->orderBy('id')
                ->pluck('database_connection_id')
                ->all(),
        );
    }

    public function test_batch_statement_execution_outcomes_are_available_on_the_request_detail_page(): void
    {
        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->create();
        $queryRequest = QueryRequest::factory()->create([
            'requester_id' => $admin->id,
            'database_connection_id' => $connection->id,
            'status' => QueryRequestStatus::Failed,
            'completed_at' => now(),
        ]);
        $succeededStatement = QueryRequestStatement::factory()->create([
            'query_request_id' => $queryRequest->id,
            'database_connection_id' => $connection->id,
            'position' => 1,
        ]);
        $failedStatement = QueryRequestStatement::factory()->create([
            'query_request_id' => $queryRequest->id,
            'database_connection_id' => $connection->id,
            'position' => 2,
        ]);
        QueryRequestStatement::factory()->create([
            'query_request_id' => $queryRequest->id,
            'database_connection_id' => $connection->id,
            'position' => 3,
        ]);
        QueryExecution::factory()->create([
            'query_request_id' => $queryRequest->id,
            'query_request_statement_id' => $succeededStatement->id,
            'database_connection_id' => $connection->id,
            'status' => ExecutionStatus::Succeeded,
        ]);
        QueryExecution::factory()->create([
            'query_request_id' => $queryRequest->id,
            'query_request_statement_id' => $failedStatement->id,
            'database_connection_id' => $connection->id,
            'status' => ExecutionStatus::Failed,
            'error_message' => 'Statement 2 was rejected.',
        ]);

        $this->actingAs($admin)->get(route('query-requests.show', $queryRequest))
            ->assertInertia(fn (Assert $page) => $page
                ->component('query-requests/show')
                ->where('query_request.statements.0.execution.status', ExecutionStatus::Succeeded->value)
                ->where('query_request.statements.1.execution.status', ExecutionStatus::Failed->value)
                ->where('query_request.statements.1.execution.error_message', 'Statement 2 was rejected.')
                ->where('query_request.statements.2.execution', null)
                ->where('query_request.statements.2.execution_state', 'skipped')
            );
    }

    public function test_requester_can_cancel_an_approved_query_access_request_and_revoke_its_active_sessions(): void
    {
        $requester = $this->adminUser();
        $connection = DatabaseConnection::factory()->create();
        $queryRequest = QueryRequest::factory()->queryAccess()->approved()->create([
            'requester_id' => $requester->id,
            'database_connection_id' => $connection->id,
            'status' => QueryRequestStatus::Running,
        ]);
        $queryRequest->accessConnections()->sync([$connection->id]);
        $session = QuerySession::factory()->create([
            'query_request_id' => $queryRequest->id,
            'user_id' => $requester->id,
            'database_connection_id' => $connection->id,
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($requester)
            ->from(route('query-requests.show', $queryRequest))
            ->post(route('query-requests.cancel', $queryRequest), [
                'reason' => 'Deployment window has been withdrawn.',
            ])
            ->assertRedirect(route('query-requests.show', $queryRequest));

        $queryRequest->refresh();
        $session->refresh();

        $this->assertSame(QueryRequestStatus::Cancelled, $queryRequest->status);
        $this->assertSame($requester->id, $queryRequest->cancelled_by_id);
        $this->assertSame('Deployment window has been withdrawn.', $queryRequest->cancellation_reason);
        $this->assertNotNull($queryRequest->cancelled_at);
        $this->assertNotNull($session->ended_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'query_request.cancelled',
            'auditable_id' => $queryRequest->id,
        ]);
    }

    public function test_query_access_requests_cannot_be_edited_after_creation(): void
    {
        $requester = $this->adminUser();
        $connection = DatabaseConnection::factory()->create();
        $queryRequest = QueryRequest::factory()->queryAccess()->create([
            'requester_id' => $requester->id,
            'database_connection_id' => $connection->id,
        ]);
        $queryRequest->accessConnections()->sync([$connection->id]);

        $this->actingAs($requester)
            ->get(route('query-requests.edit', $queryRequest))
            ->assertForbidden();
    }

    public function test_ending_a_session_cancels_the_query_access_request_with_a_reason(): void
    {
        $requester = $this->adminUser();
        $connection = DatabaseConnection::factory()->create();
        $queryRequest = QueryRequest::factory()->queryAccess()->approved()->create([
            'requester_id' => $requester->id,
            'database_connection_id' => $connection->id,
            'status' => QueryRequestStatus::Running,
        ]);
        $queryRequest->accessConnections()->sync([$connection->id]);
        $session = QuerySession::factory()->create([
            'query_request_id' => $queryRequest->id,
            'user_id' => $requester->id,
            'database_connection_id' => $connection->id,
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($requester)
            ->post(route('query-sessions.end', $session), [
                'reason' => 'The investigation is complete.',
            ])
            ->assertRedirect(route('query-requests.show', $queryRequest));

        $queryRequest->refresh();
        $session->refresh();

        $this->assertSame(QueryRequestStatus::Cancelled, $queryRequest->status);
        $this->assertSame('The investigation is complete.', $queryRequest->cancellation_reason);
        $this->assertNotNull($session->ended_at);
    }

    public function test_failed_read_only_batch_retries_from_the_failed_statement_without_replaying_successes(): void
    {
        Queue::fake();

        $requester = $this->adminUser();
        $connection = DatabaseConnection::factory()->create();
        $queryRequest = QueryRequest::factory()->create([
            'requester_id' => $requester->id,
            'database_connection_id' => $connection->id,
            'query_type' => QueryType::Read,
            'status' => QueryRequestStatus::Failed,
            'completed_at' => now(),
        ]);
        $firstStatement = QueryRequestStatement::factory()->create([
            'query_request_id' => $queryRequest->id,
            'database_connection_id' => $connection->id,
            'position' => 1,
            'query_type' => QueryType::Read,
        ]);
        $failedStatement = QueryRequestStatement::factory()->create([
            'query_request_id' => $queryRequest->id,
            'database_connection_id' => $connection->id,
            'position' => 2,
            'query_type' => QueryType::Read,
        ]);
        QueryExecution::factory()->create([
            'query_request_id' => $queryRequest->id,
            'query_request_statement_id' => $firstStatement->id,
            'database_connection_id' => $connection->id,
            'status' => ExecutionStatus::Succeeded,
        ]);
        QueryExecution::factory()->create([
            'query_request_id' => $queryRequest->id,
            'query_request_statement_id' => $failedStatement->id,
            'database_connection_id' => $connection->id,
            'status' => ExecutionStatus::Failed,
            'started_at' => now()->addSecond(),
        ]);

        $this->actingAs($requester)
            ->from(route('query-requests.show', $queryRequest))
            ->post(route('query-requests.retry', $queryRequest))
            ->assertRedirect(route('query-requests.show', $queryRequest));

        $queryRequest->refresh();

        $this->assertSame(QueryRequestStatus::Approved, $queryRequest->status);
        $this->assertNull($queryRequest->completed_at);
        $this->assertNull($queryRequest->last_error);
        $this->assertDatabaseCount('query_requests', 1);
        Queue::assertPushed(ExecuteQueryRequest::class, fn (ExecuteQueryRequest $job): bool => $job->queryRequestId === $queryRequest->id
            && $job->resumeFromPosition === 2);
    }

    public function test_failed_write_batch_creates_a_linked_request_for_reapproval_from_the_failed_statement(): void
    {
        $requester = $this->adminUser();
        $connection = DatabaseConnection::factory()->create();
        $queryRequest = QueryRequest::factory()->create([
            'requester_id' => $requester->id,
            'database_connection_id' => $connection->id,
            'query_type' => QueryType::Write,
            'status' => QueryRequestStatus::Failed,
            'completed_at' => now(),
            'title' => 'DEP-450 customer migration',
        ]);
        QueryRequestStatement::factory()->create([
            'query_request_id' => $queryRequest->id,
            'database_connection_id' => $connection->id,
            'position' => 1,
            'sql' => 'select 1',
            'query_type' => QueryType::Read,
        ]);
        $failedStatement = QueryRequestStatement::factory()->create([
            'query_request_id' => $queryRequest->id,
            'database_connection_id' => $connection->id,
            'position' => 2,
            'sql' => 'update customers set active = 1',
            'query_type' => QueryType::Write,
        ]);
        QueryRequestStatement::factory()->create([
            'query_request_id' => $queryRequest->id,
            'database_connection_id' => $connection->id,
            'position' => 3,
            'sql' => 'select count(*) from customers',
            'query_type' => QueryType::Read,
        ]);
        QueryExecution::factory()->create([
            'query_request_id' => $queryRequest->id,
            'query_request_statement_id' => $failedStatement->id,
            'database_connection_id' => $connection->id,
            'status' => ExecutionStatus::Failed,
        ]);

        $response = $this->actingAs($requester)
            ->post(route('query-requests.retry', $queryRequest));

        $retryRequest = QueryRequest::query()
            ->where('retry_of_id', $queryRequest->id)
            ->firstOrFail();

        $response->assertRedirect(route('query-requests.show', $retryRequest));
        $this->assertSame(QueryRequestStatus::PendingReview, $retryRequest->status);
        $this->assertTrue($retryRequest->requires_approval);
        $this->assertSame(
            ['update customers set active = 1', 'select count(*) from customers'],
            $retryRequest->statements()->pluck('sql')->all(),
        );
        $this->assertSame([1, 2], $retryRequest->statements()->pluck('position')->all());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'query_request.retry_created',
            'auditable_id' => $retryRequest->id,
        ]);
    }

    public function test_completed_query_access_request_creates_a_linked_request_with_the_same_targets(): void
    {
        $requester = $this->adminUser();
        $firstConnection = DatabaseConnection::factory()->create();
        $secondConnection = DatabaseConnection::factory()->create();
        $queryRequest = QueryRequest::factory()->queryAccess()->create([
            'requester_id' => $requester->id,
            'database_connection_id' => $firstConnection->id,
            'status' => QueryRequestStatus::Completed,
            'completed_at' => now(),
            'access_duration_minutes' => 90,
        ]);
        $queryRequest->accessConnections()->sync([$firstConnection->id, $secondConnection->id]);

        $this->actingAs($requester)->post(route('query-requests.retry', $queryRequest));

        $retryRequest = QueryRequest::query()
            ->where('retry_of_id', $queryRequest->id)
            ->firstOrFail();

        $this->assertSame(QueryRequestKind::QueryAccess, $retryRequest->request_kind);
        $this->assertSame(QueryRequestStatus::Approved, $retryRequest->status);
        $this->assertFalse($retryRequest->requires_approval);
        $this->assertSame(90, $retryRequest->access_duration_minutes);
        $this->assertSame(
            [$firstConnection->id, $secondConnection->id],
            $retryRequest->accessConnections()
                ->orderBy('database_connections.id')
                ->pluck('database_connections.id')
                ->all(),
        );
    }

    public function test_batch_execution_stops_before_the_next_statement_after_a_cancellation_request(): void
    {
        $admin = $this->adminUser();
        $queryRequest = QueryRequest::factory()->approved()->create(['requester_id' => $admin->id]);
        $this->createStatements($queryRequest);

        $fakeExecutor = new class($queryRequest) extends DatabaseQueryExecutor
        {
            /** @var array<int, string> */
            public array $executedSql = [];

            public function __construct(private readonly QueryRequest $queryRequest) {}

            public function execute(DatabaseConnection $databaseConnection, string $sql, QueryType $queryType): array
            {
                $this->executedSql[] = $sql;

                if (count($this->executedSql) === 1) {
                    $this->queryRequest->forceFill([
                        'status' => QueryRequestStatus::Cancelled,
                        'cancelled_at' => now(),
                        'cancellation_reason' => 'Stop after the current statement.',
                    ])->save();
                }

                return [
                    'row_count' => 1,
                    'sample_rows' => [],
                    'result_truncated' => false,
                ];
            }
        };

        (new ExecuteQueryRequest($queryRequest->id))->handle(
            $fakeExecutor,
            app(AuditLogger::class),
            app(DeploymentPreflight::class),
            app(NotificationDispatcher::class),
        );

        $this->assertSame(['select 1'], $fakeExecutor->executedSql);
        $this->assertSame(QueryRequestStatus::Cancelled, $queryRequest->refresh()->status);
        $this->assertSame(1, $queryRequest->executions()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'query_request.execution_stop_acknowledged',
            'auditable_id' => $queryRequest->id,
        ]);
    }

    private function adminUser(): User
    {
        $role = Role::factory()->admin()->create();

        return User::factory()->withRole($role)->create();
    }

    private function createStatements(QueryRequest $queryRequest): void
    {
        QueryRequestStatement::factory()->create([
            'query_request_id' => $queryRequest->id,
            'position' => 1,
            'sql' => 'select 1',
            'query_type' => QueryType::Read,
        ]);
        QueryRequestStatement::factory()->create([
            'query_request_id' => $queryRequest->id,
            'position' => 2,
            'sql' => 'update widgets set active = 1',
            'query_type' => QueryType::Write,
        ]);
        QueryRequestStatement::factory()->create([
            'query_request_id' => $queryRequest->id,
            'position' => 3,
            'sql' => 'select 3',
            'query_type' => QueryType::Read,
        ]);
    }
}
