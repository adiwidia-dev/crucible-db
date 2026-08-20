<?php

namespace App\Http\Controllers;

use App\Enums\AccessMode;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Models\DatabaseConnection;
use App\Models\Role;
use App\Models\RoleDatabasePermission;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return Inertia::render('roles/index', [
            'roles' => Role::query()
                ->withCount(['users', 'databasePermissions'])
                ->orderByDesc('is_admin')
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'description' => $role->description,
                    'is_admin' => $role->is_admin,
                    'users_count' => $role->users_count,
                    'database_permissions_count' => $role->database_permissions_count,
                ]),
        ]);
    }

    public function create(): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return Inertia::render('roles/form', [
            'role' => null,
            'connections' => $this->databaseConnectionOptions(),
            'access_modes' => array_map(fn (AccessMode $mode): string => $mode->value, AccessMode::cases()),
        ]);
    }

    public function store(StoreRoleRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $role = DB::transaction(function () use ($request): Role {
            $role = Role::query()->create($request->roleAttributes());
            $this->syncDatabasePolicies($role, $request->policyAttributes());

            return $role;
        });

        $auditLogger->log('role.created', $request->user(), $role, [
            'role_id' => $role->id,
            'database_policy_count' => $role->databasePermissions()->count(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Role created.']);

        return redirect()->route('roles.index');
    }

    public function show(Role $role): RedirectResponse
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return redirect()->route('roles.edit', $role);
    }

    public function edit(Role $role): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);
        abort_if($role->is_admin, 403);

        $role->load('databasePermissions');

        return Inertia::render('roles/form', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'is_admin' => $role->is_admin,
                'policies' => $role->databasePermissions->mapWithKeys(fn (RoleDatabasePermission $permission): array => [
                    (string) $permission->database_connection_id => [
                        'access_mode' => $permission->access_mode->value,
                        'can_review' => $permission->can_review,
                        'read_requires_approval' => $permission->read_requires_approval,
                        'write_requires_approval' => $permission->write_requires_approval,
                        'max_write_session_minutes' => $permission->max_write_session_minutes,
                    ],
                ]),
            ],
            'connections' => $this->databaseConnectionOptions(),
            'access_modes' => array_map(fn (AccessMode $mode): string => $mode->value, AccessMode::cases()),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role, AuditLogger $auditLogger): RedirectResponse
    {
        abort_if($role->is_admin, 403);

        $before = $role->only(['name', 'slug', 'description']);

        DB::transaction(function () use ($request, $role): void {
            $role->update($request->roleAttributes());
            $this->syncDatabasePolicies($role, $request->policyAttributes());
        });

        $auditLogger->log('role.updated', $request->user(), $role, [
            'role_id' => $role->id,
            'before' => $before,
            'after' => $role->only(['name', 'slug', 'description']),
            'database_policy_count' => $role->databasePermissions()->count(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Role updated.']);

        return redirect()->route('roles.index');
    }

    public function destroy(Role $role, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless(request()->user()->isAdmin(), 403);
        abort_if($role->is_admin, 403);

        if ($role->users()->exists() || $role->databasePermissions()->exists()) {
            return back()->withErrors([
                'role' => 'Role cannot be deleted while users or database permissions are attached.',
            ]);
        }

        $roleId = $role->id;
        $roleName = $role->name;

        $role->delete();

        $auditLogger->log('role.deleted', request()->user(), $role, [
            'role_id' => $roleId,
            'role_name' => $roleName,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Role deleted.']);

        return redirect()->route('roles.index');
    }

    /**
     * @return Collection<int, array{id: int, name: string, driver: 'mysql'|'pgsql', host: string, port: int, database: string, is_active: bool}>
     */
    private function databaseConnectionOptions(): Collection
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
     * @param  array<int, array{database_connection_id: int, access_mode: string, can_review: bool, read_requires_approval: bool, write_requires_approval: bool, max_write_session_minutes: int|null}>  $policies
     */
    private function syncDatabasePolicies(Role $role, array $policies): void
    {
        $submittedConnectionIds = [];

        foreach ($policies as $policy) {
            $submittedConnectionIds[] = $policy['database_connection_id'];
        }

        if ($submittedConnectionIds === []) {
            $role->databasePermissions()->delete();
        } else {
            $role->databasePermissions()
                ->whereNotIn('database_connection_id', $submittedConnectionIds)
                ->delete();
        }

        foreach ($policies as $policy) {
            $hasRuntimeEffect = $policy['access_mode'] !== AccessMode::None->value || $policy['can_review'];

            if (! $hasRuntimeEffect) {
                $role->databasePermissions()
                    ->where('database_connection_id', $policy['database_connection_id'])
                    ->delete();

                continue;
            }

            $role->databasePermissions()->updateOrCreate(
                [
                    'database_connection_id' => $policy['database_connection_id'],
                ],
                [
                    'access_mode' => $policy['access_mode'],
                    'can_review' => $policy['can_review'],
                    'requires_approval' => $policy['read_requires_approval']
                        && $policy['write_requires_approval'],
                    'read_requires_approval' => $policy['read_requires_approval'],
                    'write_requires_approval' => $policy['write_requires_approval'],
                    'max_write_session_minutes' => $policy['max_write_session_minutes'],
                ],
            );
        }
    }
}
