<?php

namespace App\Http\Controllers;

use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Services\DatabaseSchemaBrowser;
use App\Services\QuerySessionWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class QuerySessionController extends Controller
{
    public function store(QueryRequest $queryRequest, QuerySessionWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('startSession', $queryRequest);

        $session = $workflow->start($queryRequest, request()->user());

        return redirect()->route('query-sessions.show', $session);
    }

    public function show(QuerySession $querySession, DatabaseSchemaBrowser $schemaBrowser): Response
    {
        Gate::authorize('view', $querySession);

        $querySession->load(['databaseConnection', 'databaseConnections', 'queryRequest.requester']);
        $activeConnection = $querySession->databaseConnections
            ->firstWhere('id', request()->integer('connection_id'));

        if (request()->filled('connection_id') && $activeConnection === null) {
            abort(404);
        }

        $activeConnection ??= $querySession->databaseConnections->first() ?? $querySession->databaseConnection;
        $queries = $querySession->queries()
            ->with('databaseConnection')
            ->latest()
            ->limit(20)
            ->get();
        $latestQuery = $queries->first();

        if ($latestQuery?->database_connection_id !== $activeConnection->id) {
            $latestQuery = null;
        }

        try {
            $tables = $schemaBrowser->tables($activeConnection);
        } catch (Throwable) {
            $tables = [];
        }

        return Inertia::render('query-sessions/show', [
            'session' => [
                'id' => $querySession->id,
                'started_at' => $querySession->started_at->toIso8601String(),
                'expires_at' => $querySession->expires_at->toIso8601String(),
                'ended_at' => $querySession->ended_at?->toIso8601String(),
                'is_active' => $querySession->isActive(),
                'request' => [
                    'id' => $querySession->queryRequest->id,
                    'title' => $querySession->queryRequest->title,
                    'requester' => $querySession->queryRequest->requester->name,
                ],
                'connection' => $this->connectionSummary($activeConnection),
                'connections' => $querySession->databaseConnections
                    ->map(fn ($connection): array => $this->connectionSummary($connection))
                    ->values(),
                'latest_query' => $latestQuery ? [
                    'id' => $latestQuery->id,
                    'sql' => $latestQuery->sql,
                    'query_type' => $latestQuery->query_type->value,
                    'status' => $latestQuery->status->value,
                    'duration_ms' => $latestQuery->duration_ms,
                    'row_count' => $latestQuery->row_count,
                    'result_truncated' => $latestQuery->result_truncated,
                    'sample_rows' => $latestQuery->sample_rows,
                    'error_message' => $latestQuery->error_message,
                    'created_at' => $latestQuery->created_at?->toIso8601String(),
                ] : null,
                'queries' => $queries->map(fn ($query): array => [
                    'id' => $query->id,
                    'sql' => $query->sql,
                    'query_type' => $query->query_type->value,
                    'status' => $query->status->value,
                    'row_count' => $query->row_count,
                    'result_truncated' => $query->result_truncated,
                    'duration_ms' => $query->duration_ms,
                    'error_message' => $query->error_message,
                    'created_at' => $query->created_at?->toIso8601String(),
                    'connection' => $query->databaseConnection
                        ? $this->connectionSummary($query->databaseConnection)
                        : null,
                ])->values(),
            ],
            'tables' => $tables,
        ]);
    }

    public function end(QuerySession $querySession, QuerySessionWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('end', $querySession);

        $workflow->end($querySession, request()->user());

        return redirect()->route('query-requests.show', $querySession->query_request_id);
    }

    /**
     * @return array{id:int, name:string, driver:string}
     */
    private function connectionSummary(DatabaseConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'name' => $connection->name,
            'driver' => $connection->driver->value,
        ];
    }
}
