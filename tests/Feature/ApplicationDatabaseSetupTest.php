<?php

namespace Tests\Feature;

use App\Enums\ApplicationDatabaseDriver;
use App\Models\User;
use App\Support\ApplicationDatabaseBootstrap;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationDatabaseSetupTest extends TestCase
{
    use RefreshDatabase;

    private string $configurationDirectory;

    private string $configurationPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurationDirectory = storage_path('framework/testing/application-database-'.bin2hex(random_bytes(8)));
        $this->configurationPath = $this->configurationDirectory.'/configuration.enc';
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->configurationDirectory);

        parent::tearDown();
    }

    public function test_fresh_managed_install_redirects_to_database_selection_before_owner_setup(): void
    {
        $this->configureManagedMode();

        $this->get(route('home'))
            ->assertRedirect(route('setup.database.create'));

        $this->get(route('setup.show'))
            ->assertRedirect(route('setup.database.create'));

        $this->get(route('setup.database.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('setup/application-database')
                ->has('drivers', 3));
    }

    public function test_environment_managed_install_skips_database_selection(): void
    {
        config(['database.control_metadata.mode' => ApplicationDatabaseBootstrap::EnvironmentMode]);

        $this->get(route('setup.database.create'))
            ->assertRedirect(route('setup.show'));

        $this->get(route('setup.show'))->assertOk();
    }

    public function test_sqlite_selection_writes_encrypted_configuration_and_requires_restart(): void
    {
        $this->configureManagedMode();

        $this->post(route('setup.database.store'), [
            'driver' => ApplicationDatabaseDriver::Sqlite->value,
        ])->assertRedirect(route('setup.database.restart'));

        $this->assertFileExists($this->configurationPath);
        $contents = file_get_contents($this->configurationPath);
        $this->assertIsString($contents);
        $this->assertStringNotContainsString('sqlite', $contents);

        $this->get(route('setup.show'))
            ->assertRedirect(route('setup.database.restart'));

        $this->get(route('setup.database.restart'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('setup/database-restart'));
    }

    public function test_owner_setup_resumes_after_the_saved_configuration_is_active(): void
    {
        $this->configureManagedMode();
        $this->post(route('setup.database.store'), [
            'driver' => ApplicationDatabaseDriver::Sqlite->value,
        ]);

        $payload = ApplicationDatabaseBootstrap::read(
            $this->configurationPath,
            (string) config('app.key'),
            (string) config('app.cipher'),
        );
        $this->assertNotNull($payload);

        config([
            'database.control_metadata.configured' => true,
            'database.control_metadata.fingerprint' => ApplicationDatabaseBootstrap::fingerprint($payload),
        ]);

        $this->get(route('setup.database.restart'))
            ->assertRedirect(route('setup.show'));

        $this->get(route('setup.show'))->assertOk();
    }

    public function test_existing_install_without_a_managed_configuration_remains_available(): void
    {
        $this->configureManagedMode();
        User::factory()->create();

        $this->get(route('home'))
            ->assertRedirect(route('login'));

        $this->get(route('setup.database.create'))->assertNotFound();
    }

    public function test_network_database_fields_are_validated_conditionally(): void
    {
        $this->configureManagedMode();

        $this->post(route('setup.database.store'), [
            'driver' => ApplicationDatabaseDriver::PostgreSql->value,
        ])->assertSessionHasErrors([
            'host',
            'port',
            'database',
            'username',
            'password',
            'pgsql_sslmode',
        ]);

        $this->assertFileDoesNotExist($this->configurationPath);
    }

    private function configureManagedMode(): void
    {
        config([
            'database.control_metadata.mode' => ApplicationDatabaseBootstrap::ManagedMode,
            'database.control_metadata.path' => $this->configurationPath,
            'database.control_metadata.configured' => false,
            'database.control_metadata.fingerprint' => null,
        ]);

    }
}
