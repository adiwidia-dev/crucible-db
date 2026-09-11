<?php

namespace Tests\Feature;

use App\Support\ApplicationDatabaseMigrationStore;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class ApplicationDatabaseMigrationCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/application-database-command-'.bin2hex(random_bytes(8)));
        config([
            'database.control_metadata.migration_directory' => $this->directory.'/plans',
            'database.control_metadata.migration_fence_path' => $this->directory.'/migration.fence',
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_inspect_reports_active_database_without_exposing_password_or_requiring_a_plan(): void
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('crucible:application-database:inspect');
        $command
            ->expectsOutputToContain('Active driver')
            ->expectsOutputToContain('No migration plan exists')
            ->assertSuccessful();
    }

    public function test_migration_plans_are_encrypted_and_reject_a_second_in_progress_plan(): void
    {
        $store = app(ApplicationDatabaseMigrationStore::class);
        $state = $store->create([
            'status' => 'planned',
            'source' => ['payload' => ['password' => 'source-secret']],
            'destination' => ['payload' => ['password' => 'destination-secret']],
        ]);
        $path = $this->directory.'/plans/'.$state['id'].'.enc';
        $contents = file_get_contents($path);

        $this->assertIsString($contents);
        $this->assertStringNotContainsString('source-secret', $contents);
        $this->assertStringNotContainsString('destination-secret', $contents);
        $this->assertSame('source-secret', $store->read($state['id'])['source']['payload']['password']);

        $this->expectException(\RuntimeException::class);
        $store->create(['status' => 'planned']);
    }
}
