<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Models\DatabaseConnection;
use App\Models\Role;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Support\SessionKey;
use Tests\TestCase;

class AdminRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_identity_admin_pages(): void
    {
        $developer = $this->developerUser();

        $this->actingAs($developer)->get(route('roles.index'))->assertForbidden();
        $this->actingAs($developer)->get(route('users.index'))->assertForbidden();
    }

    public function test_admin_can_create_update_and_delete_custom_role(): void
    {
        $admin = $this->adminUser();
        $primaryConnection = DatabaseConnection::factory()->create(['name' => 'Primary']);
        $reviewConnection = DatabaseConnection::factory()->create(['name' => 'Review Target']);

        $storeResponse = $this->actingAs($admin)->post(route('roles.store'), [
            'name' => 'Data Reviewer',
            'description' => 'Can review database query requests.',
            'policies' => [
                [
                    'database_connection_id' => $primaryConnection->id,
                    'access_mode' => AccessMode::Read->value,
                    'can_review' => false,
                    'requires_approval' => false,
                ],
                [
                    'database_connection_id' => $reviewConnection->id,
                    'access_mode' => AccessMode::None->value,
                    'can_review' => true,
                    'requires_approval' => true,
                ],
            ],
        ]);

        $role = Role::query()->where('slug', 'data-reviewer')->firstOrFail();

        $storeResponse
            ->assertRedirect(route('roles.index'))
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => [
                    'type' => 'success',
                    'message' => 'Role created.',
                ],
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role.created',
            'auditable_id' => $role->id,
        ]);
        $this->assertDatabaseHas('role_database_permissions', [
            'role_id' => $role->id,
            'database_connection_id' => $primaryConnection->id,
            'access_mode' => AccessMode::Read->value,
            'can_review' => false,
            'requires_approval' => false,
        ]);
        $this->assertDatabaseHas('role_database_permissions', [
            'role_id' => $role->id,
            'database_connection_id' => $reviewConnection->id,
            'access_mode' => AccessMode::None->value,
            'can_review' => true,
            'requires_approval' => true,
        ]);

        $this->actingAs($admin)->put(route('roles.update', $role), [
            'name' => 'Query Reviewer',
            'description' => 'Reviews query requests before execution.',
            'policies' => [
                [
                    'database_connection_id' => $primaryConnection->id,
                    'access_mode' => AccessMode::Write->value,
                    'can_review' => true,
                    'requires_approval' => true,
                ],
                [
                    'database_connection_id' => $reviewConnection->id,
                    'access_mode' => AccessMode::None->value,
                    'can_review' => false,
                    'requires_approval' => true,
                ],
            ],
        ])->assertRedirect(route('roles.index'));

        $role->refresh();

        $this->assertSame('Query Reviewer', $role->name);
        $this->assertSame('query-reviewer', $role->slug);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role.updated',
            'auditable_id' => $role->id,
        ]);
        $this->assertDatabaseHas('role_database_permissions', [
            'role_id' => $role->id,
            'database_connection_id' => $primaryConnection->id,
            'access_mode' => AccessMode::Write->value,
            'can_review' => true,
            'requires_approval' => true,
        ]);
        $this->assertDatabaseMissing('role_database_permissions', [
            'role_id' => $role->id,
            'database_connection_id' => $reviewConnection->id,
        ]);

        $this->actingAs($admin)->delete(route('roles.destroy', $role))
            ->assertRedirect()
            ->assertSessionHasErrors('role');

        RoleDatabasePermission::query()->where('role_id', $role->id)->delete();

        $this->actingAs($admin)->delete(route('roles.destroy', $role))
            ->assertRedirect(route('roles.index'));

        $this->assertModelMissing($role);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role.deleted',
        ]);
    }

    public function test_admin_role_is_protected_from_ui_mutations(): void
    {
        $admin = $this->adminUser();
        $adminRole = $admin->roles()->firstOrFail();

        $this->actingAs($admin)->get(route('roles.edit', $adminRole))->assertForbidden();
        $this->actingAs($admin)->put(route('roles.update', $adminRole), [
            'name' => 'Root',
            'description' => 'Should not update.',
        ])->assertForbidden();
        $this->actingAs($admin)->delete(route('roles.destroy', $adminRole))->assertForbidden();

        $this->assertSame('Admin', $adminRole->refresh()->name);
    }

    public function test_role_cannot_be_deleted_while_users_or_permissions_are_attached(): void
    {
        $admin = $this->adminUser();
        $role = Role::factory()->developer()->create();

        User::factory()->withRole($role)->create();

        $this->actingAs($admin)->delete(route('roles.destroy', $role))
            ->assertRedirect()
            ->assertSessionHasErrors('role');

        $this->assertModelExists($role);

        $role->users()->detach();
        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => DatabaseConnection::factory()->create()->id,
            'access_mode' => AccessMode::Read,
        ]);

        $this->actingAs($admin)->delete(route('roles.destroy', $role))
            ->assertRedirect()
            ->assertSessionHasErrors('role');

        $this->assertModelExists($role);
    }

    public function test_admin_can_assign_user_to_multiple_roles(): void
    {
        $admin = $this->adminUser();
        $developer = $this->developerUser();
        $reviewerRole = Role::factory()->create([
            'name' => 'Reviewer',
            'slug' => 'reviewer',
        ]);
        $auditorRole = Role::factory()->create([
            'name' => 'Auditor',
            'slug' => 'auditor',
        ]);

        $this->actingAs($admin)->patch(route('users.role.update', $developer), [
            'role_assignments' => [
                ['role_id' => $reviewerRole->id, 'selected' => true, 'priority' => 10],
                ['role_id' => $auditorRole->id, 'selected' => true, 'priority' => 20],
            ],
        ])->assertRedirect()
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => [
                    'type' => 'success',
                    'message' => 'User roles updated.',
                ],
            ]);

        $developer->refresh();

        $this->assertSame($reviewerRole->id, $developer->role_id);
        $this->assertSame(
            [
                $reviewerRole->id => 10,
                $auditorRole->id => 20,
            ],
            $developer->roles()->pluck('role_user.priority', 'roles.id')->all(),
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.roles_assigned',
            'auditable_id' => $developer->id,
        ]);
    }

    public function test_admin_cannot_assign_duplicate_role_priorities_to_user(): void
    {
        $admin = $this->adminUser();
        $developer = $this->developerUser();
        $reviewerRole = Role::factory()->create(['slug' => 'reviewer']);
        $auditorRole = Role::factory()->create(['slug' => 'auditor']);

        $this->actingAs($admin)->patch(route('users.role.update', $developer), [
            'role_assignments' => [
                ['role_id' => $reviewerRole->id, 'selected' => true, 'priority' => 10],
                ['role_id' => $auditorRole->id, 'selected' => true, 'priority' => 10],
            ],
        ])->assertRedirect()
            ->assertSessionHasErrors('role_assignments');
    }

    public function test_admin_cannot_change_their_own_role(): void
    {
        $admin = $this->adminUser();
        $developerRole = Role::factory()->developer()->create();
        $originalRoleIds = $admin->roles()->pluck('roles.id')->all();

        $this->actingAs($admin)->patch(route('users.role.update', $admin), [
            'role_assignments' => [
                ['role_id' => $developerRole->id, 'selected' => true, 'priority' => 10],
            ],
        ])->assertRedirect()
            ->assertSessionHasErrors('role_assignments');

        $this->assertEqualsCanonicalizing(
            $originalRoleIds,
            $admin->refresh()->roles()->pluck('roles.id')->all(),
        );
    }

    private function adminUser(): User
    {
        $role = Role::factory()->admin()->create();

        return User::factory()->withRole($role)->create();
    }

    private function developerUser(): User
    {
        $role = Role::factory()->developer()->create();

        return User::factory()->withRole($role)->create();
    }
}
