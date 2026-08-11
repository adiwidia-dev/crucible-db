<?php

namespace App\Http\Controllers;

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

        $querySession->load(['queryRequest.requester', 'databaseConnection']);
        $queries = $querySession->queries()->latest()->limit(20)->get();
        $latestQuery = $queries->first();

        try {
            $tables = $schemaBrowser->tables($querySession->databaseConnection);
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
                'connection' => [
                    'id' => $querySession->databaseConnection->id,
                    'name' => $querySession->databaseConnection->name,
                    'driver' => $querySession->databaseConnection->driver->value,
                ],
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
}
