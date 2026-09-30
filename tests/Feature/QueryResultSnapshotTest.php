<?php

namespace Tests\Feature;

use App\Models\QueryResultSnapshot;
use App\Models\QuerySession;
use App\Models\QuerySessionQuery;
use App\Models\User;
use App\Services\QueryResultSnapshotStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueryResultSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_query_result_snapshot_is_encrypted_and_paginated(): void
    {
        $user = User::factory()->create();
        $session = QuerySession::factory()->for($user)->create();
        $query = QuerySessionQuery::factory()
            ->for($session)
            ->for($user)
            ->create();
        $rows = collect(range(1, 150))
            ->map(fn (int $id): array => ['id' => $id, 'name' => "Employee {$id}"])
            ->all();
        $snapshot = QueryResultSnapshot::factory()
            ->for($query, 'querySessionQuery')
            ->create([
                'rows' => $rows,
                'row_count' => count($rows),
                'byte_count' => strlen(json_encode($rows, JSON_THROW_ON_ERROR)),
            ]);

        $this->assertStringNotContainsString(
            'Employee 1',
            $snapshot->getRawOriginal('rows'),
        );

        $this->actingAs($user)
            ->getJson(route('query-session-queries.results', [
                'query_session_query' => $query,
                'page' => 2,
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.id', 101)
            ->assertJsonPath('data.49.id', 150)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 150);
    }

    public function test_result_snapshot_is_unavailable_after_its_session_ends(): void
    {
        $user = User::factory()->create();
        $session = QuerySession::factory()->for($user)->create([
            'ended_at' => now(),
        ]);
        $query = QuerySessionQuery::factory()
            ->for($session)
            ->for($user)
            ->create();

        QueryResultSnapshot::factory()
            ->for($query, 'querySessionQuery')
            ->create();

        $this->actingAs($user)
            ->getJson(route('query-session-queries.results', $query))
            ->assertNotFound();
    }

    public function test_result_snapshots_are_removed_with_their_session(): void
    {
        $session = QuerySession::factory()->create();
        $query = QuerySessionQuery::factory()->for($session)->create();
        $snapshot = QueryResultSnapshot::factory()
            ->for($query, 'querySessionQuery')
            ->create();

        app(QueryResultSnapshotStore::class)->forgetForSession($session);

        $this->assertModelMissing($snapshot);
    }
}
