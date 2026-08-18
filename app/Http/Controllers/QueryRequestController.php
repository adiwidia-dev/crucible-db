<?php

namespace App\Http\Controllers;

use App\Enums\ExecutionStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Http\Requests\StoreQueryRequestRequest;
use App\Http\Requests\UpdateQueryRequestRequest;
use App\Models\DatabaseConnection;
use App\Models\QueryExecution;
use App\Models\QueryRequest;
use App\Models\QueryRequestStatement;
use App\Models\QuerySession;
use App\Models\QuerySessionQuery;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\QueryRequestWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class QueryRequestController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', QueryRequest::class);

        $user = request()->user();
        $query = QueryRequest::query()
            ->with(['requester', 'databaseConnection', 'latestExecution', 'latestSession'])
            ->withExists([
                'executions as has_write_execution' => fn (Builder $query) => $query->where('query_type', QueryType::Write->value),
            ])
            ->latest();

        if (! $user->isAdmin()) {
            $reviewableConnectionIds = $user->reviewableDatabaseConnectionIds();
            $accessibleConnectionIds = $user->accessibleDatabaseConnectionIds();
            $visibleConnectionIds = collect($accessibleConnectionIds)
                ->merge($reviewableConnectionIds)
                ->unique()
                ->values()
                ->all();

            $query->where(function ($requests) use ($user, $visibleConnectionIds): void {
                $requests->where('requester_id', $user->id)
                    ->orWhereIn('database_connection_id', $visibleConnectionIds);
            });
        }

        $filters = $this->filters();
        $this->applyFilters($query, $filters);

        $connectionOptions = DatabaseConnection::query()
            ->when(! $user->isAdmin(), fn ($connections) => $connections->whereIn('id', $user->accessibleDatabaseConnectionIds()))
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('query-requests/index', [
            'query_requests' => $query->paginate(15)->withQueryString()->through(fn (QueryRequest $queryRequest): array => [
                'id' => $queryRequest->id,
                'title' => $queryRequest->title,
                'status' => $queryRequest->status->value,
                'query_type' => $queryRequest->query_type->value,
                'latest_query_type' => $queryRequest->latestExecution?->query_type?->value,
                'effective_query_type' => $this->effectiveQueryType($queryRequest),
                'request_kind' => $queryRequest->request_kind->value,
                'requires_approval' => $queryRequest->requires_approval,
                'scheduled_at' => $queryRequest->scheduled_at?->toIso8601String(),
                'requester' => $queryRequest->requester->name,
                'connection' => $queryRequest->databaseConnection->name,
                'created_at' => $queryRequest->created_at?->toIso8601String(),
                'active_session_expires_at' => $this->activeSessionExpiresAt($queryRequest),
                'latest_session_expires_at' => $this->latestSessionExpiresAt($queryRequest),
            ]),
            'filters' => $filters,
            'filter_options' => [
                'connections' => $connectionOptions->map(fn (DatabaseConnection $connection): array => [
                    'id' => $connection->id,
                    'name' => $connection->name,
                ]),
                'statuses' => array_map(fn (QueryRequestStatus $status): string => $status->value, QueryRequestStatus::cases()),
                'request_kinds' => array_map(fn (QueryRequestKind $kind): string => $kind->value, QueryRequestKind::cases()),
                'query_types' => array_map(fn (QueryType $type): string => $type->value, QueryType::cases()),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', QueryRequest::class);

        $user = request()->user();

        return Inertia::render('query-requests/create', [
            'connections' => $this->connectionOptions($user),
            'query_request' => null,
        ]);
    }

    public function store(StoreQueryRequestRequest $request, QueryRequestWorkflow $workflow): RedirectResponse
    {
        $data = [
            'database_connection_id' => $request->integer('database_connection_id'),
            'database_connection_ids' => $request->validated('database_connection_ids', []),
            'request_kind' => $request->string('request_kind')->toString(),
            'title' => $request->string('title')->toString(),
            'description' => $request->filled('description') ? $request->string('description')->toString() : null,
            'statements' => $request->validated('statements', []),
            'scheduled_at' => $request->filled('scheduled_at') ? $request->string('scheduled_at')->toString() : null,
            'access_duration_minutes' => $request->filled('access_duration_minutes') ? $request->integer('access_duration_minutes') : null,
        ];

        $this->authorizeRequestedConnections($request->user(), $data);

        $queryRequest = $workflow->create($request->user(), $data);

        return redirect()->route('query-requests.show', $queryRequest);
    }

    public function show(QueryRequest $queryRequest): Response
    {
        Gate::authorize('view', $queryRequest);

        $queryRequest->load([
            'requester',
            'approvedBy',
            'databaseConnection',
            'accessConnections',
            'reviews.reviewer',
            'statements.databaseConnection',
        ]);

        $sessions = $queryRequest->sessions()
            ->latest('started_at')
            ->limit(20)
            ->get();

        $statementExecutions = $queryRequest->executions()
            ->whereNotNull('query_request_statement_id')
            ->latest('started_at')
            ->get()
            ->unique('query_request_statement_id')
            ->keyBy('query_request_statement_id');
        $failedExecution = $statementExecutions->first(
            fn (QueryExecution $execution): bool => $execution->status === ExecutionStatus::Failed,
        );
        $failedStatementPosition = $queryRequest->status === QueryRequestStatus::Failed
            && $failedExecution?->query_request_statement_id
            ? $queryRequest->statements
                ->firstWhere('id', $failedExecution->query_request_statement_id)
                ?->position
            : null;

        $executions = $queryRequest->executions()
            ->with(['databaseConnection', 'executor', 'statement.databaseConnection'])
            ->latest('started_at')
            ->paginate(10, ['*'], 'executions_page')
            ->withQueryString()
            ->through(fn (QueryExecution $execution): array => [
                'id' => $execution->id,
                'statement_position' => $execution->statement?->position,
                'connection' => $execution->databaseConnection
                    ? $this->connectionSummary($execution->databaseConnection)
                    : $this->statementConnection($execution->statement, $queryRequest),
                'status' => $execution->status->value,
                // query_type is nullable for executions created before SQL metadata was introduced.
                /** @phpstan-ignore-next-line nullsafe.neverNull */
                'query_type' => $execution->query_type?->value ?? $queryRequest->query_type->value,
                'sql' => $execution->sql ?? $queryRequest->sql,
                'started_at' => $execution->started_at?->toIso8601String(),
                'finished_at' => $execution->finished_at?->toIso8601String(),
                'duration_ms' => $execution->duration_ms,
                'row_count' => $execution->row_count,
                'result_truncated' => $execution->result_truncated,
                'sample_rows' => $execution->sample_rows,
                'error_message' => $execution->error_message,
                'executor' => $execution->executor?->name,
            ]);

        $activeSession = $queryRequest->sessions()
            ->whereNull('ended_at')
            ->where('expires_at', '>', now())
            ->when(! request()->user()->isAdmin(), fn ($query) => $query->where('user_id', request()->user()->id))
            ->latest('started_at')
            ->first();

        return Inertia::render('query-requests/show', [
            'query_request' => [
                'id' => $queryRequest->id,
                'title' => $queryRequest->title,
                'description' => $queryRequest->description,
                'sql' => $queryRequest->sql,
                'statements' => $queryRequest->statements->map(function (QueryRequestStatement $statement) use ($failedStatementPosition, $queryRequest, $statementExecutions): array {
                    $execution = $statementExecutions->get($statement->id);
                    $executionState = $execution?->status->value;

                    if (
                        $executionState === null
                        && $failedStatementPosition !== null
                        && $statement->position > $failedStatementPosition
                    ) {
                        $executionState = 'skipped';
                    }

                    return [
                        'id' => $statement->id,
                        'position' => $statement->position,
                        'sql' => $statement->sql,
                        'query_type' => $statement->query_type->value,
                        'connection' => $this->statementConnection($statement, $queryRequest),
                        'execution' => $execution ? [
                            'status' => $execution->status->value,
                            'error_message' => $execution->error_message,
                        ] : null,
                        'execution_state' => $executionState,
                    ];
                })->values(),
                'status' => $queryRequest->status->value,
                'query_type' => $queryRequest->query_type->value,
                'request_kind' => $queryRequest->request_kind->value,
                'requires_approval' => $queryRequest->requires_approval,
                'scheduled_at' => $queryRequest->scheduled_at?->toIso8601String(),
                'access_duration_minutes' => $queryRequest->access_duration_minutes,
                'created_at' => $queryRequest->created_at?->toIso8601String(),
                'approved_at' => $queryRequest->approved_at?->toIso8601String(),
                'dispatched_at' => $queryRequest->dispatched_at?->toIso8601String(),
                'completed_at' => $queryRequest->completed_at?->toIso8601String(),
                'last_error' => $queryRequest->last_error,
                'result_summary' => $queryRequest->result_summary,
                'requester' => $queryRequest->requester->name,
                'approved_by' => $queryRequest->approvedBy?->name,
                'connection' => [
                    'id' => $queryRequest->databaseConnection->id,
                    'name' => $queryRequest->databaseConnection->name,
                    'driver' => $queryRequest->databaseConnection->driver->value,
                ],
                'access_connections' => $queryRequest->accessConnections->map(fn (DatabaseConnection $connection): array => [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'driver' => $connection->driver->value,
                ])->values(),
                'reviews' => $queryRequest->reviews->map(fn ($review): array => [
                    'id' => $review->id,
                    'decision' => $review->decision,
                    'comment' => $review->comment,
                    'reviewer' => $review->reviewer->name,
                    'created_at' => $review->created_at?->toIso8601String(),
                ])->values(),
                'executions' => $executions,
                'sessions' => $sessions->map(fn (QuerySession $session): array => [
                    'id' => $session->id,
                    'started_at' => $session->started_at->toIso8601String(),
                    'expires_at' => $session->expires_at->toIso8601String(),
                    'ended_at' => $session->ended_at?->toIso8601String(),
                ])->values(),
                'active_session' => $activeSession ? [
                    'id' => $activeSession->id,
                    'expires_at' => $activeSession->expires_at->toIso8601String(),
                ] : null,
            ],
            'can_review' => request()->user()->can('review', $queryRequest),
            'can_update' => request()->user()->can('update', $queryRequest),
            'can_dispatch' => request()->user()->can('dispatch', $queryRequest),
            'can_start_session' => $activeSession === null && request()->user()->can('startSession', $queryRequest),
            'can_delete' => request()->user()->can('delete', $queryRequest),
            'statuses' => array_map(fn (QueryRequestStatus $status): string => $status->value, QueryRequestStatus::cases()),
        ]);
    }

    public function dispatch(QueryRequest $queryRequest, QueryRequestWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('dispatch', $queryRequest);

        $workflow->dispatch($queryRequest, request()->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Query scheduled for immediate execution.']);

        return back();
    }

    public function destroy(QueryRequest $queryRequest, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('delete', $queryRequest);

        $queryRequest->load([
            'requester',
            'databaseConnection',
        ]);

        $sessionCount = $queryRequest->sessions()->count();
        $sessionQueryCount = QuerySessionQuery::query()
            ->whereIn('query_session_id', $queryRequest->sessions()->select('id'))
            ->count();

        $auditLogger->log('query_request.deleted', request()->user(), $queryRequest, [
            'query_request_id' => $queryRequest->id,
            'title' => $queryRequest->title,
            'request_kind' => $queryRequest->request_kind->value,
            'status' => $queryRequest->status->value,
            'query_type' => $queryRequest->query_type->value,
            'requires_approval' => $queryRequest->requires_approval,
            'requester_id' => $queryRequest->requester_id,
            'requester' => $queryRequest->requester->name,
            'database_connection_id' => $queryRequest->database_connection_id,
            'database_connection' => $queryRequest->databaseConnection->name,
            'created_at' => $queryRequest->created_at?->toIso8601String(),
            'approved_at' => $queryRequest->approved_at?->toIso8601String(),
            'completed_at' => $queryRequest->completed_at?->toIso8601String(),
            'session_count' => $sessionCount,
            'session_query_count' => $sessionQueryCount,
            'execution_count' => $queryRequest->executions()->count(),
            'review_count' => $queryRequest->reviews()->count(),
            'statement_count' => $queryRequest->statements()->count(),
        ]);

        $queryRequest->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Query access request deleted.',
        ]);

        return redirect()->route('query-requests.index');
    }

    /**
     * @return array{search: string, status: string, request_kind: string, query_type: string, connection_id: string}
     */
    private function filters(): array
    {
        return [
            'search' => request()->string('search')->trim()->toString(),
            'status' => $this->enumFilter('status', QueryRequestStatus::cases()),
            'request_kind' => $this->enumFilter('request_kind', QueryRequestKind::cases()),
            'query_type' => $this->enumFilter('query_type', QueryType::cases()),
            'connection_id' => request()->filled('connection_id') ? (string) request()->integer('connection_id') : '',
        ];
    }

    /**
     * @param  array<int, QueryRequestStatus|QueryRequestKind|QueryType>  $cases
     */
    private function enumFilter(string $key, array $cases): string
    {
        $value = request()->string($key)->toString();
        $allowed = array_map(fn ($case): string => $case->value, $cases);

        return in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * @param  Builder<QueryRequest>  $query
     * @param  array{search: string, status: string, request_kind: string, query_type: string, connection_id: string}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['search'];

                $query->where(function (Builder $query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhereHas('requester', fn (Builder $requesters) => $requesters->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('databaseConnection', fn (Builder $connections) => $connections->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['request_kind'] !== '', fn (Builder $query) => $query->where('request_kind', $filters['request_kind']))
            ->when($filters['query_type'] !== '', fn (Builder $query) => $query->where('query_type', $filters['query_type']))
            ->when($filters['connection_id'] !== '', fn (Builder $query) => $query->where('database_connection_id', $filters['connection_id']));
    }

    private function activeSessionExpiresAt(QueryRequest $queryRequest): ?string
    {
        $session = $queryRequest->latestSession;

        return $session?->isActive() ? $session->expires_at->toIso8601String() : null;
    }

    private function latestSessionExpiresAt(QueryRequest $queryRequest): ?string
    {
        return $queryRequest->latestSession?->expires_at->toIso8601String();
    }

    private function effectiveQueryType(QueryRequest $queryRequest): string
    {
        if ((bool) $queryRequest->has_write_execution) {
            return QueryType::Write->value;
        }

        // query_type is nullable for executions created before SQL metadata was introduced.
        /** @phpstan-ignore-next-line nullsafe.neverNull */
        return $queryRequest->latestExecution?->query_type?->value
            ?? $queryRequest->query_type->value;
    }

    public function edit(QueryRequest $queryRequest): Response
    {
        Gate::authorize('update', $queryRequest);

        $queryRequest->load(['statements', 'accessConnections']);

        return Inertia::render('query-requests/create', [
            'connections' => $this->connectionOptions(request()->user()),
            'query_request' => [
                'id' => $queryRequest->id,
                'database_connection_id' => $queryRequest->database_connection_id,
                'database_connection_ids' => $queryRequest->accessConnections
                    ->pluck('id')
                    ->whenEmpty(fn () => collect([$queryRequest->database_connection_id]))
                    ->values(),
                'request_kind' => $queryRequest->request_kind->value,
                'title' => $queryRequest->title,
                'description' => $queryRequest->description,
                'statements' => $queryRequest->statements->map(fn ($statement): array => [
                    'sql' => $statement->sql,
                    'database_connection_id' => $statement->database_connection_id ?? $queryRequest->database_connection_id,
                ])->values(),
                'scheduled_at' => $queryRequest->scheduled_at?->toIso8601String(),
                'access_duration_minutes' => $queryRequest->access_duration_minutes,
                'was_approved' => in_array($queryRequest->status, [QueryRequestStatus::Approved, QueryRequestStatus::Scheduled], true),
            ],
        ]);
    }

    public function update(UpdateQueryRequestRequest $request, QueryRequest $queryRequest, QueryRequestWorkflow $workflow): RedirectResponse
    {
        $data = [
            'database_connection_id' => $request->integer('database_connection_id'),
            'database_connection_ids' => $request->validated('database_connection_ids', []),
            'request_kind' => $request->string('request_kind')->toString(),
            'title' => $request->string('title')->toString(),
            'description' => $request->filled('description') ? $request->string('description')->toString() : null,
            'statements' => $request->validated('statements', []),
            'scheduled_at' => $request->filled('scheduled_at') ? $request->string('scheduled_at')->toString() : null,
            'access_duration_minutes' => $request->filled('access_duration_minutes') ? $request->integer('access_duration_minutes') : null,
        ];

        $this->authorizeRequestedConnections($request->user(), $data);

        $queryRequest = $workflow->update($queryRequest, $request->user(), $data);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Query request updated and returned for approval.',
        ]);

        return redirect()->route('query-requests.show', $queryRequest);
    }

    /**
     * @return Collection<int, array{id:int, name:string, driver:'mysql'|'pgsql'}>
     */
    private function connectionOptions(User $user): Collection
    {
        return DatabaseConnection::query()
            ->where('is_active', true)
            ->when(! $user->isAdmin(), fn ($query) => $query->whereIn('id', $user->accessibleDatabaseConnectionIds()))
            ->orderBy('name')
            ->get()
            ->map(fn (DatabaseConnection $connection): array => [
                'id' => $connection->id,
                'name' => $connection->name,
                'driver' => $connection->driver->value,
            ]);
    }

    /**
     * @param  array{database_connection_id:int,database_connection_ids:array<int, int>,request_kind:string,statements:array<int, array{database_connection_id:int}>}  $data
     */
    private function authorizeRequestedConnections(User $user, array $data): void
    {
        $connectionIds = $data['request_kind'] === QueryRequestKind::SingleExecution->value
            ? collect($data['statements'])->pluck('database_connection_id')->unique()
            : collect($data['database_connection_ids']);

        DatabaseConnection::query()
            ->whereKey($connectionIds)
            ->get()
            ->each(fn (DatabaseConnection $connection) => Gate::authorize('view', $connection));
    }

    /**
     * @return array{id:int, name:string, driver:string}
     */
    private function statementConnection(?QueryRequestStatement $statement, QueryRequest $queryRequest): array
    {
        $connection = $statement instanceof QueryRequestStatement
            ? $statement->databaseConnection
            : $queryRequest->databaseConnection;

        return $this->connectionSummary($connection);
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
