<?php

namespace App\Http\Controllers;

use App\Enums\DatabaseDriver;
use App\Enums\ExecutionStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Http\Requests\CancelQueryRequestRequest;
use App\Http\Requests\RetryQueryRequestRequest;
use App\Http\Requests\StoreQueryRequestRequest;
use App\Http\Requests\UpdateQueryRequestRequest;
use App\Models\DatabaseConnection;
use App\Models\QueryExecution;
use App\Models\QueryRequest;
use App\Models\QueryRequestStatement;
use App\Models\QuerySession;
use App\Models\QuerySessionQuery;
use App\Models\User;
use App\Services\ApplicationSettings;
use App\Services\AuditLogger;
use App\Services\QueryRequestWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
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
                'requested_access_mode' => $queryRequest->requested_access_mode?->value,
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

    public function create(ApplicationSettings $settings): Response
    {
        Gate::authorize('create', QueryRequest::class);

        $user = request()->user();

        return Inertia::render('query-requests/create', [
            'connections' => $this->connectionOptions($user),
            'query_request' => null,
            'sql_statement_policy' => $settings->sqlStatementPolicyFormValues(),
        ]);
    }

    public function store(StoreQueryRequestRequest $request, QueryRequestWorkflow $workflow): RedirectResponse
    {
        $data = [
            'database_connection_id' => $request->integer('database_connection_id'),
            'database_connection_ids' => $request->validated('database_connection_ids', []),
            'request_kind' => $request->string('request_kind')->toString(),
            'requested_access_mode' => $request->filled('requested_access_mode') ? $request->string('requested_access_mode')->toString() : null,
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
            'cancelledBy',
            'databaseConnection',
            'accessConnections',
            'retryOf',
            'retries',
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
            ->latest('id')
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
            ->latest('id')
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
                'requested_access_mode' => $queryRequest->requested_access_mode?->value,
                'requires_approval' => $queryRequest->requires_approval,
                'scheduled_at' => $queryRequest->scheduled_at?->toIso8601String(),
                'approved_after_schedule' => $queryRequest->request_kind === QueryRequestKind::SingleExecution
                    && $queryRequest->status === QueryRequestStatus::Approved
                    && $queryRequest->dispatched_at === null
                    && $queryRequest->scheduled_at !== null
                    && $queryRequest->approved_at !== null
                    && $queryRequest->approved_at->isAfter($queryRequest->scheduled_at),
                'access_duration_minutes' => $queryRequest->access_duration_minutes,
                'created_at' => $queryRequest->created_at?->toIso8601String(),
                'approved_at' => $queryRequest->approved_at?->toIso8601String(),
                'dispatched_at' => $queryRequest->dispatched_at?->toIso8601String(),
                'completed_at' => $queryRequest->completed_at?->toIso8601String(),
                'cancelled_at' => $queryRequest->cancelled_at?->toIso8601String(),
                'cancellation_reason' => $queryRequest->cancellation_reason,
                'last_error' => $queryRequest->last_error,
                'result_summary' => $queryRequest->result_summary,
                'preflight' => $this->preflightSummary($queryRequest),
                'requester' => $queryRequest->requester->name,
                'approved_by' => $queryRequest->approvedBy?->name,
                'cancelled_by' => $queryRequest->cancelledBy?->name,
                'retry_of' => $queryRequest->retryOf ? [
                    'id' => $queryRequest->retryOf->id,
                    'title' => $queryRequest->retryOf->title,
                ] : null,
                'retries' => $queryRequest->retries->map(fn (QueryRequest $retry): array => [
                    'id' => $retry->id,
                    'title' => $retry->title,
                    'status' => $retry->status->value,
                ])->values(),
                'connection' => [
                    'id' => $queryRequest->databaseConnection->id,
                    'name' => $queryRequest->databaseConnection->name,
                    'driver' => $queryRequest->databaseConnection->driver->value,
                ],
                'access_connections' => $queryRequest->accessConnections->map(fn (DatabaseConnection $connection): array => [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'driver' => match ($connection->driver) {
                        DatabaseDriver::MySql => 'mysql',
                        DatabaseDriver::PostgreSql => 'pgsql',
                    },
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
            'can_cancel' => request()->user()->can('cancel', $queryRequest),
            'can_retry' => request()->user()->can('retry', $queryRequest),
            'retry_strategy' => $this->retryStrategy($queryRequest),
            'can_start_session' => $activeSession === null && request()->user()->can('startSession', $queryRequest),
            'can_delete' => request()->user()->can('delete', $queryRequest),
            'is_subscribed' => $queryRequest->notificationSubscriptions()
                ->whereBelongsTo(request()->user())
                ->exists(),
            'statuses' => array_map(fn (QueryRequestStatus $status): string => $status->value, QueryRequestStatus::cases()),
        ]);
    }

    public function dispatch(QueryRequest $queryRequest, QueryRequestWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('dispatch', $queryRequest);

        $dispatched = $workflow->dispatch($queryRequest, request()->user());

        Inertia::flash('toast', $dispatched
            ? ['type' => 'success', 'message' => 'Deployment batch queued for execution.']
            : ['type' => 'error', 'message' => 'Deployment batch is blocked by its latest preflight checks.']);

        return back();
    }

    public function cancel(CancelQueryRequestRequest $request, QueryRequest $queryRequest, QueryRequestWorkflow $workflow): RedirectResponse
    {
        $cancelledRequest = $workflow->cancel(
            $queryRequest,
            $request->user(),
            $request->validated('reason'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $cancelledRequest->request_kind === QueryRequestKind::SingleExecution
                && $cancelledRequest->dispatched_at !== null
                ? 'Stop requested. The current statement can finish, but no later statements will run.'
                : 'Query request cancelled.',
        ]);

        return back();
    }

    public function retry(RetryQueryRequestRequest $request, QueryRequest $queryRequest, QueryRequestWorkflow $workflow): RedirectResponse
    {
        $retryRequest = $workflow->retry($queryRequest, $request->user());

        if ($retryRequest->id !== $queryRequest->id) {
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => 'A linked retry request was created and requires approval before execution.',
            ]);

            return redirect()->route('query-requests.show', $retryRequest);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Read-only retry queued from the failed statement.',
        ]);

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

        $queryRequest->notificationSubscriptions()->delete();
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

    private function retryStrategy(QueryRequest $queryRequest): ?string
    {
        if ($queryRequest->request_kind === QueryRequestKind::QueryAccess
            && in_array($queryRequest->status, [QueryRequestStatus::Completed, QueryRequestStatus::Cancelled], true)) {
            return 'renew_access';
        }

        if ($queryRequest->request_kind !== QueryRequestKind::SingleExecution
            || $queryRequest->status !== QueryRequestStatus::Failed) {
            return null;
        }

        return $queryRequest->query_type === QueryType::Read
            ? 'resume_read_only'
            : 'create_retry_request';
    }

    public function edit(QueryRequest $queryRequest, ApplicationSettings $settings): Response
    {
        Gate::authorize('update', $queryRequest);

        $queryRequest->load(['statements', 'accessConnections']);

        return Inertia::render('query-requests/create', [
            'connections' => $this->connectionOptions(request()->user()),
            'sql_statement_policy' => $settings->sqlStatementPolicyFormValues(),
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
            'requested_access_mode' => $request->filled('requested_access_mode') ? $request->string('requested_access_mode')->toString() : null,
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
     * @return array<int, array{id:int, name:string, driver:'mysql'|'pgsql', can_write:bool, read_requires_approval:bool, write_requires_approval:bool, max_write_session_minutes:int|null}>
     */
    private function connectionOptions(User $user): array
    {
        return DatabaseConnection::query()
            ->where('is_active', true)
            ->when(! $user->isAdmin(), fn ($query) => $query->whereIn('id', $user->accessibleDatabaseConnectionIds()))
            ->orderBy('name')
            ->get()
            ->map(function (DatabaseConnection $connection) use ($user): array {
                $readPermission = $user->effectiveDatabasePermissionFor($connection, QueryType::Read);
                $writePermission = $user->effectiveDatabasePermissionFor($connection, QueryType::Write);

                return [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'driver' => $connection->driver->value,
                    'can_write' => $writePermission['access_mode']->allows(QueryType::Write),
                    'read_requires_approval' => $readPermission['read_requires_approval'],
                    'write_requires_approval' => $writePermission['write_requires_approval'],
                    'max_write_session_minutes' => $writePermission['max_write_session_minutes'],
                ];
            })
            ->values()
            ->all();
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

    /**
     * @return array{status:string,checked_at:string|null,blocker_count:int,warning_count:int,statements:array<int, mixed>}
     */
    private function preflightSummary(QueryRequest $queryRequest): array
    {
        $report = $queryRequest->preflight_report ?? [];
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];

        return [
            'status' => $queryRequest->preflight_status->value,
            'checked_at' => $queryRequest->preflight_checked_at?->toIso8601String(),
            'blocker_count' => (int) ($summary['blocker_count'] ?? 0),
            'warning_count' => (int) ($summary['warning_count'] ?? 0),
            'statements' => is_array($report['statements'] ?? null) ? $report['statements'] : [],
        ];
    }
}
