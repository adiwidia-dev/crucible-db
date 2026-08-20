<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\Role;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use App\Services\DatabaseQueryExecutor;
use App\Services\QueryRequestWorkflow;
use App\Services\QuerySessionWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QueryAccessPolicyTest extends TestCase
{
    use RefreshDatabase;

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

        $fakeExecutor = new class extends DatabaseQueryExecutor
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
            'read_requires_approval' => false,
            'write_requires_approval' => true,
            'max_write_session_minutes' => 30,
        ]);

        return [$user, $connection];
    }
}
