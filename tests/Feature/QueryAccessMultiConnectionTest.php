<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Models\DatabaseConnection;
use App\Models\Role;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use App\Services\DatabaseQueryExecutor;
use App\Services\QueryRequestWorkflow;
use App\Services\QuerySessionWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QueryAccessMultiConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_access_requires_approval_when_any_selected_connection_requires_it(): void
    {
        $role = Role::factory()->developer()->create();
        $requester = User::factory()->withRole($role)->create();
        $firstConnection = DatabaseConnection::factory()->create();
        $secondConnection = DatabaseConnection::factory()->create();
        RoleDatabasePermission::factory()->bypassApproval()->create([
            'role_id' => $role->id,
            'database_connection_id' => $firstConnection->id,
        ]);
        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $secondConnection->id,
            'requires_approval' => true,
        ]);

        $queryRequest = app(QueryRequestWorkflow::class)->create($requester, [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'database_connection_ids' => [$firstConnection->id, $secondConnection->id],
            'title' => 'Investigate cross-service production data',
            'access_duration_minutes' => 30,
        ]);

        $this->assertSame(QueryRequestStatus::PendingReview, $queryRequest->status);
        $this->assertTrue($queryRequest->requires_approval);
        $this->assertSame(
            [$firstConnection->id, $secondConnection->id],
            $queryRequest->accessConnections()->pluck('database_connection_id')->sort()->values()->all(),
        );
    }

    public function test_query_access_session_executes_on_the_selected_scoped_connection(): void
    {
        $role = Role::factory()->developer()->create();
        $requester = User::factory()->withRole($role)->create();
        $firstConnection = DatabaseConnection::factory()->create();
        $secondConnection = DatabaseConnection::factory()->create();

        foreach ([$firstConnection, $secondConnection] as $connection) {
            RoleDatabasePermission::factory()->create([
                'role_id' => $role->id,
                'database_connection_id' => $connection->id,
                'access_mode' => AccessMode::Read,
                'requires_approval' => false,
            ]);
        }

        $fakeExecutor = new class extends DatabaseQueryExecutor
        {
            /** @var array<int, int> */
            public array $connectionIds = [];

            public function execute(DatabaseConnection $databaseConnection, string $sql, QueryType $queryType): array
            {
                $this->connectionIds[] = $databaseConnection->id;

                return [
                    'row_count' => 1,
                    'sample_rows' => [['value' => 1]],
                    'result_truncated' => false,
                ];
            }
        };
        $this->app->instance(DatabaseQueryExecutor::class, $fakeExecutor);

        $queryRequest = app(QueryRequestWorkflow::class)->create($requester, [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'database_connection_ids' => [$firstConnection->id, $secondConnection->id],
            'title' => 'Investigate cross-service production data',
            'access_duration_minutes' => 30,
        ]);
        $session = app(QuerySessionWorkflow::class)->start($queryRequest, $requester);

        app(QuerySessionWorkflow::class)->execute(
            $session,
            $requester,
            'select 1 as value',
            $secondConnection,
        );

        $this->assertSame(
            [$firstConnection->id, $secondConnection->id],
            $session->databaseConnections()->pluck('database_connection_id')->sort()->values()->all(),
        );
        $this->assertSame([$secondConnection->id], $fakeExecutor->connectionIds);
        $this->assertDatabaseHas('query_session_queries', [
            'query_session_id' => $session->id,
            'database_connection_id' => $secondConnection->id,
        ]);
        $this->assertDatabaseHas('query_executions', [
            'query_request_id' => $queryRequest->id,
            'database_connection_id' => $secondConnection->id,
        ]);
    }

    public function test_query_access_session_rejects_a_connection_outside_its_approved_scope(): void
    {
        $role = Role::factory()->developer()->create();
        $requester = User::factory()->withRole($role)->create();
        $approvedConnection = DatabaseConnection::factory()->create();
        $outsideConnection = DatabaseConnection::factory()->create();
        RoleDatabasePermission::factory()->bypassApproval()->create([
            'role_id' => $role->id,
            'database_connection_id' => $approvedConnection->id,
        ]);

        $queryRequest = app(QueryRequestWorkflow::class)->create($requester, [
            'request_kind' => QueryRequestKind::QueryAccess->value,
            'database_connection_ids' => [$approvedConnection->id],
            'title' => 'Investigate production data',
            'access_duration_minutes' => 30,
        ]);
        $session = app(QuerySessionWorkflow::class)->start($queryRequest, $requester);

        try {
            app(QuerySessionWorkflow::class)->execute(
                $session,
                $requester,
                'select 1 as value',
                $outsideConnection,
            );
            $this->fail('An unapproved connection must not be executable in the session.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Select a connection approved for this session.',
                $exception->errors()['database_connection_id'][0],
            );
        }

        $this->assertDatabaseCount('query_session_queries', 0);
    }
}
