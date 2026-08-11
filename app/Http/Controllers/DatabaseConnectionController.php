<?php

namespace App\Http\Controllers;

use App\Enums\DatabaseDriver;
use App\Enums\QueryType;
use App\Http\Requests\StoreDatabaseConnectionRequest;
use App\Http\Requests\UpdateDatabaseConnectionRequest;
use App\Models\DatabaseConnection;
use App\Services\AuditLogger;
use App\Services\DatabaseQueryExecutor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DatabaseConnectionController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', DatabaseConnection::class);

        $user = request()->user();
        $query = DatabaseConnection::query()->withCount('queryRequests')->latest();

        if (! $user->isAdmin()) {
            $query->whereIn('id', $user->accessibleDatabaseConnectionIds());
        }

        return Inertia::render('connections/index', [
            'connections' => $query->paginate(15)->through(fn (DatabaseConnection $connection): array => [
                'id' => $connection->id,
                'name' => $connection->name,
                'driver' => $connection->driver->value,
                'host' => $connection->host,
                'port' => $connection->port,
                'database' => $connection->database,
                'username' => $connection->username,
                'is_active' => $connection->is_active,
                'query_requests_count' => $connection->query_requests_count,
            ]),
            'can_create' => $user->can('create', DatabaseConnection::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', DatabaseConnection::class);

        return Inertia::render('connections/form', [
            'connection' => null,
            'drivers' => $this->drivers(),
        ]);
    }

    public function store(StoreDatabaseConnectionRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $connection = DatabaseConnection::query()->create([
            ...$request->validated(),
            'created_by_id' => $request->user()->id,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $auditLogger->log('database_connection.created', $request->user(), $connection);

        return redirect()->route('connections.show', $connection);
    }

    public function show(DatabaseConnection $databaseConnection): Response
    {
        Gate::authorize('view', $databaseConnection);

        $databaseConnection->load(['rolePermissions.role']);

        return Inertia::render('connections/show', [
            'connection' => [
                'id' => $databaseConnection->id,
                'name' => $databaseConnection->name,
                'driver' => $databaseConnection->driver->value,
                'host' => $databaseConnection->host,
                'port' => $databaseConnection->port,
                'database' => $databaseConnection->database,
                'username' => $databaseConnection->username,
                'ssl_mode' => $databaseConnection->ssl_mode,
                'is_active' => $databaseConnection->is_active,
                'permissions' => $databaseConnection->rolePermissions->map(fn ($permission): array => [
                    'id' => $permission->id,
                    'role' => $permission->role->name,
                    'access_mode' => $permission->access_mode->value,
                    'can_review' => $permission->can_review,
                    'requires_approval' => $permission->requires_approval,
                ])->values(),
            ],
            'can_update' => request()->user()->can('update', $databaseConnection),
        ]);
    }

    public function edit(DatabaseConnection $databaseConnection): Response
    {
        Gate::authorize('update', $databaseConnection);

        return Inertia::render('connections/form', [
            'connection' => [
                'id' => $databaseConnection->id,
                'name' => $databaseConnection->name,
                'driver' => $databaseConnection->driver->value,
                'host' => $databaseConnection->host,
                'port' => $databaseConnection->port,
                'database' => $databaseConnection->database,
                'username' => $databaseConnection->username,
                'ssl_mode' => $databaseConnection->ssl_mode,
                'is_active' => $databaseConnection->is_active,
            ],
            'drivers' => $this->drivers(),
        ]);
    }

    public function update(UpdateDatabaseConnectionRequest $request, DatabaseConnection $databaseConnection, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validated();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $data['is_active'] = $request->boolean('is_active');

        $databaseConnection->update($data);
        $auditLogger->log('database_connection.updated', $request->user(), $databaseConnection);

        return redirect()->route('connections.show', $databaseConnection);
    }

    public function destroy(DatabaseConnection $databaseConnection, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('delete', $databaseConnection);

        $auditLogger->log('database_connection.deleted', request()->user(), $databaseConnection);
        $databaseConnection->delete();

        return redirect()->route('connections.index');
    }

    public function test(DatabaseConnection $databaseConnection, DatabaseQueryExecutor $executor, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('update', $databaseConnection);

        try {
            $executor->execute($databaseConnection, 'select 1 as crucible_health_check', QueryType::Read);
            $auditLogger->log('database_connection.tested', request()->user(), $databaseConnection, ['result' => 'ok']);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Connection test succeeded.']);

            return back();
        } catch (Throwable $exception) {
            $auditLogger->log('database_connection.test_failed', request()->user(), $databaseConnection, [
                'error' => $exception->getMessage(),
            ]);
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Connection test failed. Check the connection settings and audit log.']);

            return back()->withErrors(['connection' => $exception->getMessage()]);
        }
    }

    /**
     * @return array<int, array{value:string,label:string,default_port:int}>
     */
    private function drivers(): array
    {
        return array_map(fn (DatabaseDriver $driver): array => [
            'value' => $driver->value,
            'label' => match ($driver) {
                DatabaseDriver::MySql => 'MySQL',
                DatabaseDriver::PostgreSql => 'PostgreSQL',
            },
            'default_port' => $driver->defaultPort(),
        ], DatabaseDriver::cases());
    }
}
