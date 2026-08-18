<?php

use App\Http\Controllers\DashboardController;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

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
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('connections', DatabaseConnectionController::class)
        ->parameters(['connections' => 'database_connection']);
    Route::post('connections/{database_connection}/test', [DatabaseConnectionController::class, 'test'])
        ->name('connections.test');

    Route::resource('query-requests', QueryRequestController::class)
        ->parameters(['query-requests' => 'query_request']);
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
