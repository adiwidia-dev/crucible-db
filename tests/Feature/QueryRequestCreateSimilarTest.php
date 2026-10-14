<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Enums\AccessTransport;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\QueryRequestStatement;
use App\Models\Role;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QueryRequestCreateSimilarTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_batch_can_be_opened_as_a_prefilled_copy_and_saved_as_a_new_draft(): void
    {
        $admin = $this->adminUser();
        $sourceOwner = User::factory()->create();
        $firstConnection = DatabaseConnection::factory()->create(['name' => 'Primary PostgreSQL']);
        $secondConnection = DatabaseConnection::factory()->mysql()->create(['name' => 'Reporting MySQL']);
        $source = QueryRequest::factory()->create([
            'requester_id' => $sourceOwner->id,
            'database_connection_id' => $firstConnection->id,
            'title' => 'Deploy customer reporting views',
            'description' => 'Original deployment notes.',
            'status' => QueryRequestStatus::Completed,
            'scheduled_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
        QueryRequestStatement::factory()->create([
            'query_request_id' => $source->id,
            'database_connection_id' => $firstConnection->id,
            'position' => 1,
            'sql' => 'select 1',
            'query_type' => QueryType::Read,
        ]);
        QueryRequestStatement::factory()->create([
            'query_request_id' => $source->id,
            'database_connection_id' => $secondConnection->id,
            'position' => 2,
            'sql' => 'select 2',
            'query_type' => QueryType::Read,
        ]);

        $this->actingAs($admin)
            ->get(route('query-requests.create-similar', $source))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('query-requests/create')
                ->where('query_request', null)
                ->where('copy_source.id', $source->id)
                ->where('copy_source.unavailable_targets', [])
                ->where('initial_values.request_kind', QueryRequestKind::SingleExecution->value)
                ->where('initial_values.title', 'Copy of: Deploy customer reporting views')
                ->where('initial_values.description', 'Original deployment notes.')
                ->where('initial_values.scheduled_at', null)
                ->where('initial_values.statements.0.database_connection_id', $firstConnection->id)
                ->where('initial_values.statements.0.sql', 'select 1')
                ->where('initial_values.statements.1.database_connection_id', $secondConnection->id)
                ->where('initial_values.statements.1.sql', 'select 2'));

        $this->assertDatabaseCount('query_requests', 1);

        $this->actingAs($admin)
            ->post(route('query-requests.store'), [
                'intent' => 'draft',
                'copied_from_id' => $source->id,
                'request_kind' => QueryRequestKind::SingleExecution->value,
                'access_transport' => AccessTransport::Browser->value,
                'title' => 'Deploy customer reporting views next month',
                'description' => 'Adjusted deployment notes.',
                'statements' => [
                    [
                        'database_connection_id' => $firstConnection->id,
                        'sql' => 'select 1',
                    ],
                    [
                        'database_connection_id' => $secondConnection->id,
                        'sql' => 'select 2',
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $copy = QueryRequest::query()->where('copied_from_id', $source->id)->sole();

        $this->assertSame($admin->id, $copy->requester_id);
        $this->assertSame(QueryRequestStatus::Draft, $copy->status);
        $this->assertNull($copy->scheduled_at);
        $this->assertNull($copy->approved_at);
        $this->assertNull($copy->completed_at);
        $this->assertSame(
            [$firstConnection->id, $secondConnection->id],
            $copy->statements()->orderBy('position')->pluck('database_connection_id')->all(),
        );
        $this->assertSame(QueryRequestStatus::Completed, $source->refresh()->status);
        $this->assertNotNull($source->completed_at);

        $this->actingAs($admin)
            ->get(route('query-requests.show', $copy))
            ->assertInertia(fn (Assert $page) => $page
                ->where('query_request.copied_from.id', $source->id));
        $this->actingAs($admin)
            ->get(route('query-requests.show', $source))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_create_similar', true)
                ->where('query_request.copies.0.id', $copy->id));
    }

    public function test_cancelled_query_access_can_be_copied_with_all_currently_available_targets(): void
    {
        $admin = $this->adminUser();
        $firstConnection = DatabaseConnection::factory()->create();
        $secondConnection = DatabaseConnection::factory()->mysql()->create();
        $source = QueryRequest::factory()->queryAccess()->create([
            'requester_id' => $admin->id,
            'database_connection_id' => $firstConnection->id,
            'status' => QueryRequestStatus::Cancelled,
            'access_transport' => AccessTransport::Browser,
            'requested_access_mode' => AccessMode::Write,
            'access_duration_minutes' => 45,
            'cancelled_at' => now(),
            'cancellation_reason' => 'No longer needed.',
        ]);
        $source->accessConnections()->sync([$firstConnection->id, $secondConnection->id]);

        $this->actingAs($admin)
            ->get(route('query-requests.create-similar', $source))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('initial_values.request_kind', QueryRequestKind::QueryAccess->value)
                ->where('initial_values.access_transport', AccessTransport::Browser->value)
                ->where('initial_values.requested_access_mode', AccessMode::Write->value)
                ->where('initial_values.access_duration_minutes', 45)
                ->where('initial_values.database_connection_ids', fn ($ids): bool => collect($ids)->sort()->values()->all() === [$firstConnection->id, $secondConnection->id])
                ->where('initial_values.scheduled_at', null));

        $this->actingAs($admin)
            ->post(route('query-requests.store'), [
                'copied_from_id' => $source->id,
                'request_kind' => QueryRequestKind::QueryAccess->value,
                'access_transport' => AccessTransport::Browser->value,
                'requested_access_mode' => AccessMode::Write->value,
                'database_connection_ids' => [$firstConnection->id, $secondConnection->id],
                'title' => 'Repeat investigation access',
                'access_duration_minutes' => 45,
            ])
            ->assertSessionHasNoErrors();

        $copy = QueryRequest::query()->where('copied_from_id', $source->id)->sole();

        $this->assertSame($admin->id, $copy->requester_id);
        $this->assertSame(QueryRequestStatus::Approved, $copy->status);
        $this->assertNull($copy->cancelled_at);
        $this->assertNull($copy->cancellation_reason);
        $this->assertSame(
            [$firstConnection->id, $secondConnection->id],
            $copy->accessConnections()->orderBy('database_connections.id')->pluck('database_connections.id')->all(),
        );
    }

    public function test_unavailable_targets_are_reported_and_not_silently_reassigned(): void
    {
        $admin = $this->adminUser();
        $availableConnection = DatabaseConnection::factory()->create(['name' => 'Available target']);
        $inactiveConnection = DatabaseConnection::factory()->create([
            'name' => 'Retired target',
            'is_active' => false,
        ]);
        $source = QueryRequest::factory()->create([
            'requester_id' => $admin->id,
            'database_connection_id' => $availableConnection->id,
            'status' => QueryRequestStatus::Completed,
            'completed_at' => now(),
        ]);
        QueryRequestStatement::factory()->create([
            'query_request_id' => $source->id,
            'database_connection_id' => $availableConnection->id,
            'position' => 1,
            'sql' => 'select 1',
            'query_type' => QueryType::Read,
        ]);
        QueryRequestStatement::factory()->create([
            'query_request_id' => $source->id,
            'database_connection_id' => $inactiveConnection->id,
            'position' => 2,
            'sql' => 'select 2',
            'query_type' => QueryType::Read,
        ]);

        $this->actingAs($admin)
            ->get(route('query-requests.create-similar', $source))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('copy_source.unavailable_targets.0.id', $inactiveConnection->id)
                ->where('copy_source.unavailable_targets.0.name', 'Retired target')
                ->where('initial_values.statements.1.database_connection_id', null)
                ->where('initial_values.statements.1.sql', 'select 2'));

        $this->actingAs($admin)
            ->post(route('query-requests.store'), [
                'intent' => 'draft',
                'copied_from_id' => $source->id,
                'request_kind' => QueryRequestKind::SingleExecution->value,
                'access_transport' => AccessTransport::Browser->value,
                'title' => 'Copy with missing target',
                'statements' => [
                    [
                        'database_connection_id' => $availableConnection->id,
                        'sql' => 'select 1',
                    ],
                    [
                        'database_connection_id' => null,
                        'sql' => 'select 2',
                    ],
                ],
            ])
            ->assertSessionHasErrors('statements.1.database_connection_id');

        $this->assertDatabaseCount('query_requests', 1);
    }

    public function test_targets_outside_the_current_requested_access_level_are_reported_as_unavailable(): void
    {
        $role = Role::factory()->developer()->create();
        $requester = User::factory()->withRole($role)->create();
        $connection = DatabaseConnection::factory()->create(['name' => 'Read-only target']);
        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'query_access_mode' => AccessMode::Read,
        ]);
        $source = QueryRequest::factory()->queryAccess()->create([
            'requester_id' => $requester->id,
            'database_connection_id' => $connection->id,
            'status' => QueryRequestStatus::Completed,
            'requested_access_mode' => AccessMode::Write,
            'completed_at' => now(),
        ]);
        $source->accessConnections()->sync([$connection->id]);

        $this->actingAs($requester)
            ->get(route('query-requests.create-similar', $source))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('copy_source.unavailable_targets.0.id', $connection->id)
                ->where('copy_source.unavailable_targets.0.name', 'Read-only target')
                ->where('initial_values.database_connection_ids', []));
    }

    public function test_only_completed_or_cancelled_requests_can_be_copy_sources(): void
    {
        $admin = $this->adminUser();
        $pendingRequest = QueryRequest::factory()->create(['requester_id' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('query-requests.create-similar', $pendingRequest))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('query-requests.store'), [
                'intent' => 'draft',
                'copied_from_id' => $pendingRequest->id,
                'request_kind' => QueryRequestKind::SingleExecution->value,
                'access_transport' => AccessTransport::Browser->value,
                'title' => 'Forged copy source',
                'statements' => [[
                    'database_connection_id' => $pendingRequest->database_connection_id,
                    'sql' => 'select 1',
                ]],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('query_requests', 1);
    }

    private function adminUser(): User
    {
        return User::factory()
            ->withRole(Role::factory()->admin()->create())
            ->create();
    }
}
