<?php

namespace Tests\Feature;

use App\Enums\ExecutionStatus;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\ExecuteQuerySessionQuery;
use App\Models\ApplicationSetting;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\Role;
use App\Models\User;
use App\Services\DatabaseQueryExecutor;
use App\Services\DatabaseTlsMaterializer;
use App\Services\QuerySessionWorkflow;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QuerySessionExecutionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_query_queue_uses_low_latency_worker_pickup(): void
    {
        $this->assertSame(1, config('queue.connections.redis-query-sessions.block_for'));
        $this->assertSame(0, config('horizon.defaults.query-session-executions.sleep'));
    }

    public function test_browser_query_is_queued_with_the_configured_timeout(): void
    {
        Queue::fake();
        [$admin, $connection, $session] = $this->activeSession();
        ApplicationSetting::factory()->create([
            'key' => 'query_access_timeout_seconds',
            'value' => '45',
        ]);

        $this->actingAs($admin)
            ->post(route('query-sessions.queries.store', $session), [
                'database_connection_id' => $connection->id,
                'sql' => 'select 1 as value',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $query = $session->queries()->sole();

        $this->assertSame(ExecutionStatus::Queued, $query->status);
        $this->assertSame(45, $query->timeout_seconds);
        $this->assertNotNull($query->execution_token);
        $this->assertDatabaseHas('query_executions', [
            'query_session_query_id' => $query->id,
            'status' => ExecutionStatus::Queued->value,
        ]);
        Queue::assertPushedOn('query-sessions', ExecuteQuerySessionQuery::class);
    }

    public function test_worker_records_backend_identity_and_successful_terminal_state(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 13:48:45.750000'));

        try {
            Queue::fake();
            [$admin, $connection, $session] = $this->activeSession();
            $this->app->instance(DatabaseQueryExecutor::class, new class(app(DatabaseTlsMaterializer::class)) extends DatabaseQueryExecutor
            {
                public function executeTracked(
                    DatabaseConnection $databaseConnection,
                    string $sql,
                    QueryType $queryType,
                    ?int $timeoutSeconds = null,
                    ?Closure $onConnected = null,
                    ?string $executionTag = null,
                ): array {
                    $onConnected?->__invoke('7319', 'crucible-browser:'.$executionTag);
                    usleep(20_000);

                    return [
                        'row_count' => 1,
                        'sample_rows' => [['value' => 1]],
                        'result_rows' => [['value' => 1]],
                        'result_byte_count' => 11,
                        'result_truncated' => false,
                    ];
                }
            });
            $workflow = app(QuerySessionWorkflow::class);
            $query = $workflow->queue($session, $admin, 'select 1 as value', $connection);

            $workflow->executeQueued($query->id);

            $query->refresh();
            $this->assertSame(ExecutionStatus::Succeeded, $query->status);
            $this->assertSame('7319', $query->backend_process_id);
            $this->assertSame(1, $query->row_count);
            $this->assertGreaterThanOrEqual(10, $query->duration_ms);
            $this->assertLessThan(250, $query->duration_ms);
            $this->assertDatabaseHas('query_executions', [
                'query_session_query_id' => $query->id,
                'status' => ExecutionStatus::Succeeded->value,
                'row_count' => 1,
                'duration_ms' => $query->duration_ms,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_polling_reload_returns_the_latest_query_terminal_state(): void
    {
        Queue::fake();
        [$admin, $connection, $session] = $this->activeSession();
        $query = app(QuerySessionWorkflow::class)->queue(
            $session,
            $admin,
            'select 1 as value',
            $connection,
        );
        $query->forceFill([
            'status' => ExecutionStatus::Succeeded,
            'row_count' => 1,
            'duration_ms' => 25,
            'finished_at' => now(),
        ])->save();
        $assetVersion = app(HandleInertiaRequests::class)->version(request());

        $this->actingAs($admin)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'query-sessions/show',
                'X-Inertia-Partial-Data' => 'session',
                'X-Inertia-Version' => $assetVersion,
            ])
            ->get(route('query-sessions.show', $session))
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'query-sessions/show')
            ->assertJsonPath('props.session.latest_query.id', $query->id)
            ->assertJsonPath('props.session.latest_query.status', ExecutionStatus::Succeeded->value)
            ->assertJsonPath('props.session.latest_query.row_count', 1)
            ->assertJsonPath('props.session.latest_query.duration_ms', 25)
            ->assertJsonMissingPath('props.tables');
    }

    public function test_queued_query_can_be_cancelled_before_a_worker_starts_it(): void
    {
        Queue::fake();
        [$admin, $connection, $session] = $this->activeSession();
        $query = app(QuerySessionWorkflow::class)->queue(
            $session,
            $admin,
            'select 1 as value',
            $connection,
        );

        $this->actingAs($admin)
            ->post(route('query-session-queries.cancel', $query))
            ->assertRedirect();

        $query->refresh();
        $this->assertSame(ExecutionStatus::Cancelled, $query->status);
        $this->assertNotNull($query->cancel_requested_at);
        $this->assertDatabaseHas('query_executions', [
            'query_session_query_id' => $query->id,
            'status' => ExecutionStatus::Cancelled->value,
        ]);
    }

    public function test_reconciler_marks_stale_browser_execution_as_timed_out(): void
    {
        Queue::fake();
        [$admin, $connection, $session] = $this->activeSession();
        $query = app(QuerySessionWorkflow::class)->queue(
            $session,
            $admin,
            'select 1 as value',
            $connection,
        );
        $query->forceFill([
            'status' => ExecutionStatus::Running,
            'timeout_seconds' => 5,
            'started_at' => now()->subMinute(),
        ])->save();
        config(['query_access.reconciliation_grace_seconds' => 1]);

        $this->artisan('crucible:reconcile-browser-query-executions')
            ->expectsOutput('Reconciled 0 queued and 1 active browser query execution(s).')
            ->assertSuccessful();

        $this->assertSame(ExecutionStatus::TimedOut, $query->refresh()->status);
        $this->assertDatabaseHas('query_executions', [
            'query_session_query_id' => $query->id,
            'status' => ExecutionStatus::TimedOut->value,
        ]);
    }

    /**
     * @return array{User, DatabaseConnection, QuerySession}
     */
    private function activeSession(): array
    {
        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();
        $connection = DatabaseConnection::factory()->create();
        $request = QueryRequest::factory()
            ->queryAccess()
            ->create([
                'requester_id' => $admin->id,
                'database_connection_id' => $connection->id,
                'status' => QueryRequestStatus::Running,
            ]);
        $session = QuerySession::factory()->create([
            'query_request_id' => $request->id,
            'user_id' => $admin->id,
            'database_connection_id' => $connection->id,
        ]);
        $session->databaseConnections()->attach($connection);

        return [$admin, $connection, $session->fresh(['queryRequest', 'databaseConnection', 'databaseConnections'])];
    }
}
