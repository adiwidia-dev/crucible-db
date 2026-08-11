<?php

use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Http\Controllers\DatabaseConnectionController;
use App\Http\Controllers\QueryExecutionExportController;
use App\Http\Controllers\QueryRequestController;
use App\Http\Controllers\QueryReviewController;
use App\Http\Controllers\QuerySessionController;
use App\Http\Controllers\QuerySessionQueryController;
use App\Http\Controllers\QuerySessionQueryExportController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SsoController;
use App\Http\Controllers\UserInvitationController;
use App\Models\AuditLog;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/health', function () {
    Cache::put('health-check', 'ok', 5);

    return response()->json([
        'status' => 'ok',
        'cache' => Cache::get('health-check'),
    ]);
})->name('health');

Route::get('/', fn () => auth()->check()
    ? redirect()->route('dashboard')
    : redirect()->route('login'))->name('home');

Route::middleware(['guest', 'throttle:6,1'])->group(function (): void {
    Route::get('setup', [SetupController::class, 'show'])->name('setup.show');
    Route::post('setup', [SetupController::class, 'store'])->name('setup.store');
});

Route::middleware(['auth'])->group(function (): void {
    Route::get('setup/connection', [SetupController::class, 'createConnection'])->name('setup.connection.create');
    Route::post('setup/connection', [SetupController::class, 'storeConnection'])->name('setup.connection.store');
    Route::post('setup/connection/skip', [SetupController::class, 'skipConnection'])->name('setup.connection.skip');
});

Route::middleware(['guest', 'signed', 'throttle:6,1'])->group(function () {
    Route::get('invitations/users/{user}/{token}', [UserInvitationController::class, 'show'])
        ->name('users.invitations.show');
    Route::post('invitations/users/{user}/{token}', [UserInvitationController::class, 'accept'])
        ->name('users.invitations.accept');
    Route::get('invitations/users/{user}/{token}/auth-providers/{auth_provider}/redirect', [SsoController::class, 'invitationRedirect'])
        ->name('users.invitations.auth-providers.redirect');
});

Route::middleware(['guest', 'throttle:12,1'])->group(function () {
    Route::get('auth-providers/{auth_provider}/redirect', [SsoController::class, 'redirect'])
        ->name('auth-providers.redirect');
});

Route::get('auth-providers/{auth_provider}/callback', [SsoController::class, 'callback'])
    ->middleware('throttle:12,1')
    ->name('auth-providers.callback');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        $user = request()->user();
        $reviewableConnectionIds = $user->isAdmin()
            ? []
            : $user->reviewableDatabaseConnectionIds();
        $accessibleConnectionIds = $user->isAdmin()
            ? []
            : $user->accessibleDatabaseConnectionIds();
        $visibleConnectionIds = $user->isAdmin()
            ? []
            : collect($accessibleConnectionIds)->merge($reviewableConnectionIds)->unique()->values()->all();
        $requestScope = fn ($query) => $user->isAdmin()
            ? $query
            : $query->where(function ($requests) use ($user, $visibleConnectionIds): void {
                $requests->where('requester_id', $user->id)
                    ->orWhereIn('database_connection_id', $visibleConnectionIds);
            });
        $enumFilter = function (string $key, array $cases): string {
            $value = request()->string($key)->toString();
            $allowed = array_map(fn ($case): string => $case->value, $cases);

            return in_array($value, $allowed, true) ? $value : '';
        };
        $filters = [
            'search' => request()->string('search')->trim()->toString(),
            'status' => $enumFilter('status', QueryRequestStatus::cases()),
            'request_kind' => $enumFilter('request_kind', QueryRequestKind::cases()),
            'query_type' => $enumFilter('query_type', QueryType::cases()),
            'connection_id' => request()->filled('connection_id') ? (string) request()->integer('connection_id') : '',
        ];
        $applyRequestFilters = function ($query) use ($filters): void {
            $query
                ->when($filters['search'] !== '', function ($query) use ($filters): void {
                    $search = $filters['search'];

                    $query->where(function ($query) use ($search): void {
                        $query->where('title', 'like', "%{$search}%")
                            ->orWhereHas('requester', fn ($requesters) => $requesters->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('databaseConnection', fn ($connections) => $connections->where('name', 'like', "%{$search}%"));
                    });
                })
                ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
                ->when($filters['request_kind'] !== '', fn ($query) => $query->where('request_kind', $filters['request_kind']))
                ->when($filters['query_type'] !== '', fn ($query) => $query->where('query_type', $filters['query_type']))
                ->when($filters['connection_id'] !== '', fn ($query) => $query->where('database_connection_id', $filters['connection_id']));
        };

        $recentRequests = $requestScope(QueryRequest::query())
            ->with(['databaseConnection:id,name,driver', 'requester:id,name,email', 'latestExecution', 'latestSession'])
            ->withExists([
                'executions as has_write_execution' => fn ($query) => $query->where('query_type', QueryType::Write->value),
            ])
            ->latest();

        $applyRequestFilters($recentRequests);

        return Inertia::render('dashboard', [
            'stats' => [
                'connections' => DatabaseConnection::query()
                    ->when(! $user->isAdmin(), fn ($query) => $query->whereIn('id', $accessibleConnectionIds))
                    ->count(),
                'pending_reviews' => $requestScope(QueryRequest::query())->where('status', 'pending_review')->count(),
                'scheduled' => $requestScope(QueryRequest::query())->where('status', 'scheduled')->count(),
                'audit_events' => $user->isAdmin() ? AuditLog::query()->count() : 0,
            ],
            'recent_request_filters' => $filters,
            'recent_request_filter_options' => [
                'connections' => DatabaseConnection::query()
                    ->when(! $user->isAdmin(), fn ($query) => $query->whereIn('id', $visibleConnectionIds))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (DatabaseConnection $connection): array => [
                        'id' => $connection->id,
                        'name' => $connection->name,
                    ]),
                'statuses' => array_map(fn (QueryRequestStatus $status): string => $status->value, QueryRequestStatus::cases()),
                'request_kinds' => array_map(fn (QueryRequestKind $kind): string => $kind->value, QueryRequestKind::cases()),
                'query_types' => array_map(fn (QueryType $type): string => $type->value, QueryType::cases()),
            ],
            'can_view_audit_events' => $user->isAdmin(),
            'recent_requests' => $recentRequests
                ->limit(8)
                ->get()
                ->map(fn (QueryRequest $queryRequest): array => [
                    'id' => $queryRequest->id,
                    'title' => $queryRequest->title,
                    'status' => $queryRequest->status->value,
                    'query_type' => $queryRequest->query_type->value,
                    'latest_query_type' => $queryRequest->latestExecution?->query_type?->value,
                    'effective_query_type' => (bool) $queryRequest->has_write_execution
                        ? QueryType::Write->value
                        : (/** @phpstan-ignore-next-line nullsafe.neverNull */
                            $queryRequest->latestExecution?->query_type?->value ?? $queryRequest->query_type->value),
                    'request_kind' => $queryRequest->request_kind->value,
                    'requires_approval' => $queryRequest->requires_approval,
                    'scheduled_at' => $queryRequest->scheduled_at?->toIso8601String(),
                    'requester' => $queryRequest->requester->name,
                    'connection' => $queryRequest->databaseConnection->name,
                    'created_at' => $queryRequest->created_at?->toIso8601String(),
                    'active_session_expires_at' => $queryRequest->latestSession?->isActive()
                        ? $queryRequest->latestSession->expires_at->toIso8601String()
                        : null,
                    'latest_session_expires_at' => $queryRequest->latestSession?->expires_at->toIso8601String(),
                ]),
        ]);
    })->name('dashboard');

    Route::resource('connections', DatabaseConnectionController::class)
        ->parameters(['connections' => 'database_connection']);
    Route::post('connections/{database_connection}/test', [DatabaseConnectionController::class, 'test'])
        ->name('connections.test');

    Route::resource('query-requests', QueryRequestController::class)
        ->parameters(['query-requests' => 'query_request'])
        ->except(['edit', 'update']);
    Route::post('query-requests/{query_request}/reviews', [QueryReviewController::class, 'store'])
        ->name('query-requests.reviews.store');
    Route::post('query-requests/{query_request}/dispatch', [QueryRequestController::class, 'dispatch'])
        ->name('query-requests.dispatch');
    Route::post('query-requests/{query_request}/sessions', [QuerySessionController::class, 'store'])
        ->name('query-requests.sessions.store');
    Route::get('query-sessions/{query_session}', [QuerySessionController::class, 'show'])
        ->name('query-sessions.show');
    Route::post('query-sessions/{query_session}/queries', [QuerySessionQueryController::class, 'store'])
        ->name('query-sessions.queries.store');
    Route::get('query-session-queries/{query_session_query}/export', QuerySessionQueryExportController::class)
        ->name('query-session-queries.export');
    Route::post('query-sessions/{query_session}/end', [QuerySessionController::class, 'end'])
        ->name('query-sessions.end');
    Route::get('query-executions/{query_execution}/export', QueryExecutionExportController::class)
        ->name('query-executions.export');

});

require __DIR__.'/settings.php';
