<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConnectionGroupRequest;
use App\Http\Requests\UpdateConnectionGroupRequest;
use App\Models\ConnectionGroup;
use App\Models\DatabaseConnection;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionGroupController extends Controller
{
    public function index(): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return Inertia::render('connection-groups/index', [
            'connection_groups' => ConnectionGroup::query()
                ->withCount(['databaseConnections', 'rolePolicies'])
                ->orderBy('name')
                ->get()
                ->map(fn (ConnectionGroup $connectionGroup): array => $this->connectionGroupPayload($connectionGroup)),
        ]);
    }

    public function create(): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return Inertia::render('connection-groups/form', [
            'connection_group' => null,
            'connections' => $this->connectionOptions(),
        ]);
    }

    public function store(StoreConnectionGroupRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $connectionGroup = DB::transaction(function () use ($request): ConnectionGroup {
            $connectionGroup = ConnectionGroup::query()->create($request->groupAttributes());
            $connectionGroup->databaseConnections()->sync($request->connectionIds());

            return $connectionGroup;
        });

        $auditLogger->log('connection_group.created', $request->user(), $connectionGroup, [
            'connection_group_id' => $connectionGroup->id,
            'database_connection_ids' => $request->connectionIds(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Connection group created.']);

        return redirect()->route('connection-groups.index');
    }

    public function show(ConnectionGroup $connectionGroup): RedirectResponse
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return redirect()->route('connection-groups.edit', $connectionGroup);
    }

    public function edit(ConnectionGroup $connectionGroup): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        $connectionGroup->loadCount(['databaseConnections', 'rolePolicies']);

        return Inertia::render('connection-groups/form', [
            'connection_group' => [
                ...$this->connectionGroupPayload($connectionGroup),
                'database_connection_ids' => $connectionGroup->databaseConnections()
                    ->pluck('database_connections.id')
                    ->all(),
            ],
            'connections' => $this->connectionOptions(),
        ]);
    }

    public function update(UpdateConnectionGroupRequest $request, ConnectionGroup $connectionGroup, AuditLogger $auditLogger): RedirectResponse
    {
        $previousConnectionIds = $connectionGroup->databaseConnections()
            ->pluck('database_connections.id')
            ->sort()
            ->values()
            ->all();
        $connectionIds = $request->connectionIds();
        $before = $connectionGroup->only(['name', 'description']);

        DB::transaction(function () use ($request, $connectionGroup, $connectionIds): void {
            $connectionGroup->update($request->groupAttributes());
            $connectionGroup->databaseConnections()->sync($connectionIds);
        });

        $auditLogger->log('connection_group.updated', $request->user(), $connectionGroup, [
            'connection_group_id' => $connectionGroup->id,
            'before' => $before,
            'after' => $connectionGroup->only(['name', 'description']),
            'previous_database_connection_ids' => $previousConnectionIds,
            'database_connection_ids' => $connectionIds,
            'affected_role_count' => $connectionGroup->rolePolicies()->count(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Connection group updated.']);

        return redirect()->route('connection-groups.index');
    }

    public function destroy(ConnectionGroup $connectionGroup, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless(request()->user()->isAdmin(), 403);

        if ($connectionGroup->rolePolicies()->exists()) {
            return back()->withErrors([
                'connection_group' => 'Remove this group from all role policies before deleting it.',
            ]);
        }

        $connectionGroupId = $connectionGroup->id;
        $connectionGroupName = $connectionGroup->name;
        $connectionIds = $connectionGroup->databaseConnections()
            ->pluck('database_connections.id')
            ->all();

        $connectionGroup->delete();

        $auditLogger->log('connection_group.deleted', request()->user(), $connectionGroup, [
            'connection_group_id' => $connectionGroupId,
            'connection_group_name' => $connectionGroupName,
            'database_connection_ids' => $connectionIds,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Connection group deleted.']);

        return redirect()->route('connection-groups.index');
    }

    /**
     * @return Collection<int, array{id: int, name: string, driver: 'mysql'|'pgsql', host: string, port: int, database: string, is_active: bool}>
     */
    private function connectionOptions(): Collection
    {
        return DatabaseConnection::query()
            ->orderBy('name')
            ->get(['id', 'name', 'driver', 'host', 'port', 'database', 'is_active'])
            ->map(fn (DatabaseConnection $connection): array => [
                'id' => $connection->id,
                'name' => $connection->name,
                'driver' => $connection->driver->value,
                'host' => $connection->host,
                'port' => $connection->port,
                'database' => $connection->database,
                'is_active' => $connection->is_active,
            ]);
    }

    /**
     * @return array{id: int, name: string, description: string|null, database_connections_count: int, role_policies_count: int}
     */
    private function connectionGroupPayload(ConnectionGroup $connectionGroup): array
    {
        return [
            'id' => $connectionGroup->id,
            'name' => $connectionGroup->name,
            'description' => $connectionGroup->description,
            'database_connections_count' => $connectionGroup->database_connections_count,
            'role_policies_count' => $connectionGroup->role_policies_count,
        ];
    }
}
