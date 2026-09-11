<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\ApplicationDatabaseConfiguration;
use App\Services\ApplicationDatabaseMigrationFence;
use App\Support\ApplicationDatabaseBootstrap;
use App\Support\ApplicationDatabaseMigrationStore;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApplicationDatabaseMigrationAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/application-database-admin-'.bin2hex(random_bytes(8)));
        mkdir($this->directory, 0700, true);
        config([
            'database.control_metadata.mode' => ApplicationDatabaseBootstrap::ManagedMode,
            'database.control_metadata.path' => $this->directory.'/active.enc',
            'database.control_metadata.migration_directory' => $this->directory.'/plans',
            'database.control_metadata.migration_fence_path' => $this->directory.'/migration.fence',
        ]);

        $payload = app(ApplicationDatabaseConfiguration::class)
            ->payloadFromConnection((array) config('database.connections.control'));
        ApplicationDatabaseBootstrap::write(
            $payload,
            $this->directory.'/active.enc',
            (string) config('app.key'),
            (string) config('app.cipher'),
        );
        config([
            'database.control_metadata.configured' => true,
            'database.control_metadata.fingerprint' => ApplicationDatabaseBootstrap::fingerprint($payload),
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_only_admins_can_open_the_application_database_page(): void
    {
        $user = User::factory()->withRole(Role::factory()->developer()->create())->create();

        $this->actingAs($user)
            ->get(route('application-database-migrations.edit'))
            ->assertForbidden();

        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();

        $this->actingAs($admin)
            ->get(route('application-database-migrations.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/admin/application-database')
                ->where('configuration_mode', 'managed')
                ->where('active_database.driver', 'sqlite')
                ->where('migration', null)
                ->where('migration_history', [])
                ->missing('active_database.password')
                ->missing('active_database.fingerprint'));
    }

    public function test_admin_can_create_an_encrypted_migration_plan_without_exposing_credentials(): void
    {
        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();
        $destination = $this->directory.'/destination.sqlite';

        $this->actingAs($admin)
            ->post(route('application-database-migrations.store'), [
                'driver' => 'sqlite',
                'sqlite_database' => $destination,
            ])
            ->assertRedirect();

        $state = app(ApplicationDatabaseMigrationStore::class)->read();
        $encrypted = (string) file_get_contents($this->directory.'/plans/'.$state['id'].'.enc');

        $this->assertSame('planned', $state['status']);
        $this->assertSame('sqlite', $state['destination']['driver']);
        $this->assertStringNotContainsString($destination, $encrypted);
        $this->assertSame(1, AuditLog::query()->where('action', 'application_database_migration.planned')->count());
        $this->assertSame('admin_plan_created', collect((array) $state['events'])->last()['event']);

        $this->actingAs($admin)
            ->get(route('application-database-migrations.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('migration.id', $state['id'])
                ->where('migration_history', [])
                ->missing('migration.source.payload')
                ->missing('migration.destination.payload')
                ->missing('migration.source.fingerprint')
                ->missing('migration.destination.fingerprint'));
    }

    public function test_only_an_admin_can_cancel_a_planned_migration(): void
    {
        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();
        $developer = User::factory()->withRole(Role::factory()->developer()->create())->create();
        $destination = $this->directory.'/cancelled-destination.sqlite';

        $this->actingAs($admin)
            ->post(route('application-database-migrations.store'), [
                'driver' => 'sqlite',
                'sqlite_database' => $destination,
            ])
            ->assertRedirect();

        $store = app(ApplicationDatabaseMigrationStore::class);
        $state = $store->read();

        $this->actingAs($developer)
            ->delete(route('application-database-migrations.destroy', [
                'migration' => $state['id'],
            ]))
            ->assertForbidden();
        $this->assertSame($state['id'], $store->currentId());

        $this->actingAs($admin)
            ->delete(route('application-database-migrations.destroy', [
                'migration' => $state['id'],
            ]))
            ->assertRedirect();

        $this->assertNull($store->currentId());
        $this->assertSame('cancelled', $store->read($state['id'])['status']);
        $this->assertSame(1, AuditLog::query()->where('action', 'application_database_migration.cancelled')->count());

        $this->actingAs($admin)
            ->get(route('application-database-migrations.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('migration', null)
                ->has('migration_history', 1)
                ->where('migration_history.0.id', $state['id'])
                ->where('migration_history.0.status', 'cancelled')
                ->where('migration_history.0.is_current', false)
                ->where('migration_history.0.can_rollback', false)
                ->missing('migration_history.0.source.payload')
                ->missing('migration_history.0.destination.payload'));
    }

    public function test_terminal_current_migration_is_moved_to_history_with_rollback_available(): void
    {
        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();
        $destination = $this->directory.'/active-destination.sqlite';

        $this->actingAs($admin)
            ->post(route('application-database-migrations.store'), [
                'driver' => 'sqlite',
                'sqlite_database' => $destination,
            ])
            ->assertRedirect();

        $store = app(ApplicationDatabaseMigrationStore::class);
        $state = $store->read();
        $store->update((string) $state['id'], function (array $state): array {
            $state['status'] = 'active';

            return $state;
        });

        $this->actingAs($admin)
            ->get(route('application-database-migrations.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('migration', null)
                ->has('migration_history', 1)
                ->where('migration_history.0.id', $state['id'])
                ->where('migration_history.0.status', 'active')
                ->where('migration_history.0.is_current', true)
                ->where('migration_history.0.can_rollback', true));
    }

    public function test_migration_history_keeps_multiple_plans_in_newest_first_order(): void
    {
        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();

        $firstId = $this->planAndCancel($admin, $this->directory.'/first-destination.sqlite');
        $secondId = $this->planAndCancel($admin, $this->directory.'/second-destination.sqlite');

        $this->actingAs($admin)
            ->get(route('application-database-migrations.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('migration', null)
                ->has('migration_history', 2)
                ->where('migration_history.0.id', $secondId)
                ->where('migration_history.1.id', $firstId));
    }

    public function test_migration_console_remains_available_while_normal_and_native_requests_are_fenced(): void
    {
        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();
        app(ApplicationDatabaseMigrationFence::class)->engage('01K4A1B2C3D4E5F6G7H8J9K0MN');

        $this->actingAs($admin)
            ->get(route('application-database-migrations.edit'))
            ->assertOk();
        $this->get(route('dashboard'))->assertServiceUnavailable();
        $this->getJson(route('internal.native-proxy.health'))->assertServiceUnavailable();
    }

    public function test_migration_console_remains_available_when_managed_configuration_requires_a_restart(): void
    {
        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();
        $pendingPayload = app(ApplicationDatabaseConfiguration::class)
            ->payloadFromConnection((array) config('database.connections.control'));
        $pendingPayload['database'] = $this->directory.'/pending-restart.sqlite';
        ApplicationDatabaseBootstrap::write(
            $pendingPayload,
            $this->directory.'/active.enc',
            (string) config('app.key'),
            (string) config('app.cipher'),
        );
        app(ApplicationDatabaseMigrationFence::class)->engage('01K4A1B2C3D4E5F6G7H8J9K0MN');

        $this->actingAs($admin)
            ->get(route('application-database-migrations.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/admin/application-database'));
    }

    public function test_destructive_actions_require_the_exact_confirmation_phrase(): void
    {
        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();

        $this->actingAs($admin)
            ->post(route('application-database-migrations.activate', [
                'migration' => '01K4A1B2C3D4E5F6G7H8J9K0MN',
            ]), ['confirmation' => 'activate'])
            ->assertSessionHasErrors('confirmation');
    }

    public function test_environment_owned_configuration_is_read_only_in_the_admin_page(): void
    {
        config(['database.control_metadata.mode' => ApplicationDatabaseBootstrap::EnvironmentMode]);
        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();

        $this->actingAs($admin)
            ->get(route('application-database-migrations.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('configuration_mode', 'environment'));
    }

    private function planAndCancel(User $admin, string $destination): string
    {
        $this->actingAs($admin)
            ->post(route('application-database-migrations.store'), [
                'driver' => 'sqlite',
                'sqlite_database' => $destination,
            ])
            ->assertRedirect();

        $store = app(ApplicationDatabaseMigrationStore::class);
        $id = (string) $store->read()['id'];

        $this->actingAs($admin)
            ->delete(route('application-database-migrations.destroy', [
                'migration' => $id,
            ]))
            ->assertRedirect();

        return $id;
    }
}
