<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Models\ConnectionGroup;
use App\Models\DatabaseConnection;
use App\Models\Role;
use App\Models\RoleConnectionGroupPolicy;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Support\SessionKey;
use Tests\TestCase;

class ConnectionGroupManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_update_a_connection_group(): void
    {
        $admin = $this->adminUser();
        $firstConnection = DatabaseConnection::factory()->create(['name' => 'Staging API']);
        $secondConnection = DatabaseConnection::factory()->create(['name' => 'Staging Worker']);

        $this->actingAs($admin)->post(route('connection-groups.store'), [
            'name' => 'Staging databases',
            'description' => 'Targets used for staging verification.',
            'database_connection_ids' => [$firstConnection->id],
        ])->assertRedirect(route('connection-groups.index'))
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => [
                    'type' => 'success',
                    'message' => 'Connection group created.',
                ],
            ]);

        $connectionGroup = ConnectionGroup::query()->where('name', 'Staging databases')->firstOrFail();

        $this->assertTrue($connectionGroup->databaseConnections()->whereKey($firstConnection)->exists());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'connection_group.created',
            'auditable_id' => $connectionGroup->id,
        ]);

        $this->actingAs($admin)->put(route('connection-groups.update', $connectionGroup), [
            'name' => 'Staging targets',
            'description' => 'Targets used for integration verification.',
            'database_connection_ids' => [$firstConnection->id, $secondConnection->id],
        ])->assertRedirect(route('connection-groups.index'));

        $connectionGroup->refresh();

        $this->assertSame('Staging targets', $connectionGroup->name);
        $this->assertEqualsCanonicalizing(
            [$firstConnection->id, $secondConnection->id],
            $connectionGroup->databaseConnections()->pluck('database_connections.id')->all(),
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'connection_group.updated',
            'auditable_id' => $connectionGroup->id,
        ]);
    }

    public function test_group_policy_grants_current_group_members_and_direct_policy_overrides_it(): void
    {
        $role = Role::factory()->developer()->create();
        $user = User::factory()->withRole($role)->create();
        $firstConnection = DatabaseConnection::factory()->create(['name' => 'Staging API']);
        $secondConnection = DatabaseConnection::factory()->create(['name' => 'Staging Worker']);
        $connectionGroup = ConnectionGroup::factory()->create();

        $connectionGroup->databaseConnections()->sync([$firstConnection->id]);
        RoleConnectionGroupPolicy::factory()->create([
            'role_id' => $role->id,
            'connection_group_id' => $connectionGroup->id,
            'access_mode' => AccessMode::Write,
            'read_requires_approval' => false,
            'write_requires_approval' => true,
        ]);

        $this->assertSame(AccessMode::Write, $user->effectiveDatabasePermission($firstConnection)['access_mode']);
        $this->assertSame([$firstConnection->id], $user->accessibleDatabaseConnectionIds());

        $connectionGroup->databaseConnections()->sync([$firstConnection->id, $secondConnection->id]);
        $user->refresh();

        $this->assertSame(AccessMode::Write, $user->effectiveDatabasePermission($secondConnection)['access_mode']);
        $this->assertEqualsCanonicalizing(
            [$firstConnection->id, $secondConnection->id],
            $user->accessibleDatabaseConnectionIds(),
        );

        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $firstConnection->id,
            'access_mode' => AccessMode::Read,
            'read_requires_approval' => false,
            'write_requires_approval' => true,
        ]);
        $user->refresh();

        $effectivePermission = $user->effectiveDatabasePermission($firstConnection);

        $this->assertSame(AccessMode::Read, $effectivePermission['access_mode']);
        $this->assertFalse($effectivePermission['read_requires_approval']);
    }

    public function test_admin_can_assign_a_connection_group_policy_to_a_role(): void
    {
        $admin = $this->adminUser();
        $connectionGroup = ConnectionGroup::factory()->create(['name' => 'Staging databases']);

        $this->actingAs($admin)->post(route('roles.store'), [
            'name' => 'Staging operator',
            'description' => 'Accesses the staging group.',
            'policies' => [],
            'group_policies' => [[
                'connection_group_id' => $connectionGroup->id,
                'access_mode' => AccessMode::Write->value,
                'can_review' => true,
                'read_requires_approval' => false,
                'write_requires_approval' => true,
                'max_write_session_minutes' => 30,
            ]],
        ])->assertRedirect(route('roles.index'));

        $role = Role::query()->where('slug', 'staging-operator')->firstOrFail();

        $this->assertDatabaseHas('role_connection_group_policies', [
            'role_id' => $role->id,
            'connection_group_id' => $connectionGroup->id,
            'access_mode' => AccessMode::Write->value,
            'can_review' => true,
            'read_requires_approval' => false,
            'write_requires_approval' => true,
            'max_write_session_minutes' => 30,
        ]);
    }

    public function test_non_admin_cannot_manage_connection_groups(): void
    {
        $developerRole = Role::factory()->developer()->create();
        $developer = User::factory()->withRole($developerRole)->create();

        $this->actingAs($developer)->get(route('connection-groups.index'))->assertForbidden();
        $this->actingAs($developer)->post(route('connection-groups.store'), [
            'name' => 'No access',
        ])->assertForbidden();
    }

    private function adminUser(): User
    {
        $adminRole = Role::factory()->admin()->create();

        return User::factory()->withRole($adminRole)->create();
    }
}
