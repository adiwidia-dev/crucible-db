<?php

namespace Tests\Feature;

use App\Enums\DatabaseDriver;
use App\Enums\DatabaseTlsMode;
use App\Models\ApplicationSetting;
use App\Models\DatabaseConnection;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitialSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_uninitialized_application_redirects_guests_to_setup(): void
    {
        $this->get(route('home'))
            ->assertRedirect(route('setup.show'));
    }

    public function test_setup_response_does_not_emit_asset_preload_headers(): void
    {
        $this->get(route('setup.show'))
            ->assertOk()
            ->assertHeaderMissing('Link');
    }

    public function test_setup_creates_the_first_administrator_and_moves_to_optional_connection_setup(): void
    {
        $this->post(route('setup.store'), [
            'app_name' => 'Crucible DB',
            'first_name' => 'First',
            'last_name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertRedirect(route('setup.connection.create'));

        $user = User::query()->where('email', 'admin@example.test')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();

        $this->assertTrue($user->isAdmin());
        $this->assertSame($adminRole->id, $user->role_id);
        $this->assertSame('Crucible DB', ApplicationSetting::query()->where('key', 'app_name')->firstOrFail()->value);
        $this->assertSame('UTC', ApplicationSetting::query()->where('key', 'default_timezone')->firstOrFail()->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'application.initialized']);
    }

    public function test_setup_is_not_available_after_the_first_user_exists(): void
    {
        User::factory()->create();

        $this->get(route('setup.show'))
            ->assertNotFound();
    }

    public function test_initial_owner_can_skip_the_optional_connection_step(): void
    {
        $role = Role::factory()->admin()->create();
        $user = User::factory()->withRole($role)->create();

        $this->actingAs($user)
            ->withSession(['setup.owner_id' => $user->id])
            ->post(route('setup.connection.skip'))
            ->assertRedirect(route('dashboard'));

        $this->assertNull(session('setup.owner_id'));
    }

    public function test_initial_owner_persists_a_normalized_tls_mode_for_their_first_connection(): void
    {
        $role = Role::factory()->admin()->create();
        $user = User::factory()->withRole($role)->create();

        $this->actingAs($user)
            ->withSession(['setup.owner_id' => $user->id])
            ->post(route('setup.connection.store'), [
                'name' => 'Initial PostgreSQL',
                'driver' => DatabaseDriver::PostgreSql->value,
                'host' => 'database.example.test',
                'port' => 5432,
                'database' => 'application',
                'username' => 'crucible',
                'password' => 'secret-password',
                'tls_mode' => DatabaseTlsMode::VerifyIdentity->value,
                'tls_ca_certificate' => 'ca certificate',
            ])
            ->assertRedirect(route('dashboard'));

        $connection = DatabaseConnection::query()->where('name', 'Initial PostgreSQL')->firstOrFail();

        $this->assertSame(DatabaseTlsMode::VerifyIdentity, $connection->tls_mode);
        $this->assertSame('ca certificate', $connection->tls_ca_certificate);
    }
}
