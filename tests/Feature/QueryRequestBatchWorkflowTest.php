<?php

namespace Tests\Feature;

use App\Enums\ExecutionStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Jobs\ExecuteQueryRequest;
use App\Models\AuditLog;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\QueryRequestStatement;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DatabaseQueryExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $queryRequest = QueryRequest::factory()->approved()->create();
        $this->createStatements($queryRequest);

        (new ExecuteQueryRequest($queryRequest->id))->handle(
            $fakeExecutor,
            app(AuditLogger::class),
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
        $queryRequest = QueryRequest::factory()->approved()->create();
        $this->createStatements($queryRequest);

        try {
            (new ExecuteQueryRequest($queryRequest->id))->handle(
                $fakeExecutor,
                app(AuditLogger::class),
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
