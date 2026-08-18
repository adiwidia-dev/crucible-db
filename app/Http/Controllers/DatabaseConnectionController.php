<?php

namespace App\Http\Controllers;

use App\Enums\DatabaseDriver;
use App\Enums\QueryType;
use App\Http\Requests\StoreDatabaseConnectionRequest;
use App\Http\Requests\UpdateDatabaseConnectionRequest;
use App\Models\DatabaseConnection;
use App\Services\AuditLogger;
use App\Services\DatabaseQueryExecutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DatabaseConnectionController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', DatabaseConnection::class);

        $user = request()->user();
        $filters = $this->filters($request);
        $query = DatabaseConnection::query()->withCount('queryRequests')->latest();

        if (! $user->isAdmin()) {
            $query->whereIn('id', $user->accessibleDatabaseConnectionIds());
        }

        $connectionCount = (clone $query)->count();
        $this->applyFilters($query, $filters);

        return Inertia::render('connections/index', [
            'connections' => $query
                ->paginate(15)
                ->withQueryString()
                ->through(fn (DatabaseConnection $connection): array => [
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
            'filters' => $filters,
            'driver_options' => $this->drivers(),
            'connection_count' => $connectionCount,
            'can_create' => $user->can('create', DatabaseConnection::class),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', DatabaseConnection::class);

        return Inertia::render('connections/form', [
            'connection' => null,
            'drivers' => $this->drivers(),
            'defaults' => $this->createDefaults($request),
        ]);
    }

    public function store(StoreDatabaseConnectionRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validated();
        unset($data['create_another']);

        $connection = DatabaseConnection::query()->create([
            ...$data,
            'created_by_id' => $request->user()->id,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $auditLogger->log('database_connection.created', $request->user(), $connection);

        if ($request->boolean('create_another')) {
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => 'Connection saved. Shared target settings are ready for the next connection.',
            ]);

            return redirect()->route('connections.create', [
                'driver' => $connection->driver->value,
                'host' => $connection->host,
                'port' => $connection->port,
                'ssl_mode' => $connection->ssl_mode,
            ]);
        }

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
            'can_create' => request()->user()->can('create', DatabaseConnection::class),
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

    /**
     * @return array{search: string, driver: string, status: string}
     */
    private function filters(Request $request): array
    {
        $driver = DatabaseDriver::tryFrom($request->string('driver')->toString());
        $status = $request->string('status')->toString();

        return [
            'search' => $request->string('search')->trim()->toString(),
            'driver' => $driver instanceof DatabaseDriver ? $driver->value : '',
            'status' => in_array($status, ['active', 'disabled'], true) ? $status : '',
        ];
    }

    /**
     * @param  Builder<DatabaseConnection>  $query
     * @param  array{search: string, driver: string, status: string}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['search'];

                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('host', 'like', "%{$search}%")
                        ->orWhere('database', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            })
            ->when($filters['driver'] !== '', function (Builder $query) use ($filters): void {
                $query->where('driver', $filters['driver']);
            })
            ->when($filters['status'] !== '', function (Builder $query) use ($filters): void {
                $query->where('is_active', $filters['status'] === 'active');
            });
    }

    /**
     * @return array{driver: string, host: string, port: int, ssl_mode: string|null}
     */
    private function createDefaults(Request $request): array
    {
        $driver = DatabaseDriver::tryFrom($request->string('driver')->toString()) ?? DatabaseDriver::MySql;
        $port = $request->integer('port');

        return [
            'driver' => $driver->value,
            'host' => $request->string('host')->trim()->substr(0, 255)->toString(),
            'port' => $port >= 1 && $port <= 65535 ? $port : $driver->defaultPort(),
            'ssl_mode' => $request->string('ssl_mode')->trim()->substr(0, 50)->toString() ?: null,
        ];
    }
}
