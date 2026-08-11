<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignUserRoleRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UserRoleController extends Controller
{
    public function index(): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        $users = User::query()
            ->with('roles:id,name,slug,is_admin')
            ->orderBy('name')
            ->get(['id', 'role_id', 'first_name', 'last_name', 'name', 'email', 'email_verified_at', 'invited_at', 'invitation_accepted_at', 'disabled_at', 'created_at'])
            ->map(fn (User $user): array => $this->userPayload($user));

        return Inertia::render('users/index', [
            'users' => $users,
            'active_users' => $users->filter(fn (array $user): bool => $user['disabled_at'] === null)->values(),
            'disabled_users' => $users->filter(fn (array $user): bool => $user['disabled_at'] !== null)->values(),
            'roles' => Role::query()
                ->orderByDesc('is_admin')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'is_admin'])
                ->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'is_admin' => $role->is_admin,
                ]),
        ]);
    }

    public function update(AssignUserRoleRequest $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->withErrors([
                'role_assignments' => 'You cannot change your own roles.',
            ]);
        }

        $oldRoleAssignments = $this->roleAssignmentsForAudit($user);
        $newRoleAssignments = $request->roleAssignments();
        $newRoleIds = $request->roleIds();

        DB::transaction(function () use ($newRoleAssignments, $newRoleIds, $user): void {
            $user->forceFill([
                'role_id' => $newRoleIds[0] ?? null,
            ])->save();
            $user->roles()->sync($newRoleAssignments);
        });

        $auditLogger->log('user.roles_assigned', $request->user(), $user, [
            'user_id' => $user->id,
            'old_role_assignments' => $oldRoleAssignments,
            'new_role_assignments' => $newRoleAssignments,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'User roles updated.']);

        return back();
    }

    public function disable(User $user, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless(request()->user()->isAdmin(), 403);

        if ($user->is(request()->user())) {
            return back()->withErrors([
                'user' => 'You cannot disable your own account.',
            ]);
        }

        if ($user->disabled_at !== null) {
            return back();
        }

        $user->forceFill([
            'disabled_at' => now(),
        ])->save();

        $auditLogger->log('user.disabled', request()->user(), $user, [
            'user_id' => $user->id,
            'email' => $user->email,
            'role_assignments' => $this->roleAssignmentsForAudit($user),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'User disabled.']);

        return back();
    }

    public function enable(User $user, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless(request()->user()->isAdmin(), 403);

        if ($user->disabled_at === null) {
            return back();
        }

        $user->forceFill([
            'disabled_at' => null,
        ])->save();

        $auditLogger->log('user.enabled', request()->user(), $user, [
            'user_id' => $user->id,
            'email' => $user->email,
            'role_assignments' => $this->roleAssignmentsForAudit($user),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'User enabled.']);

        return back();
    }

    /**
     * @return array{id: int, first_name: string|null, last_name: string|null, name: string, email: string, email_verified_at: string|null, invited_at: string|null, invitation_accepted_at: string|null, disabled_at: string|null, created_at: string|null, role_ids: array<int, int>, roles: Collection<int, array{id: int, name: string, slug: string, is_admin: bool, priority: int}>, is_current_user: bool}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'invited_at' => $user->invited_at?->toIso8601String(),
            'invitation_accepted_at' => $user->invitation_accepted_at?->toIso8601String(),
            'disabled_at' => $user->disabled_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'role_ids' => $user->roles->pluck('id')->all(),
            'roles' => $user->roles->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'is_admin' => $role->is_admin,
                'priority' => $this->rolePriority($role),
            ])->values(),
            'is_current_user' => $user->is(request()->user()),
        ];
    }

    /**
     * @return array<int, array{priority: int}>
     */
    private function roleAssignmentsForAudit(User $user): array
    {
        return $user->roles()
            ->get(['roles.id'])
            ->mapWithKeys(fn (Role $role): array => [
                $role->id => ['priority' => $this->rolePriority($role)],
            ])
            ->all();
    }

    private function rolePriority(Role $role): int
    {
        return (int) $role->pivot?->getAttribute('priority');
    }
}
