<?php

namespace Tests\Feature;

use App\Services\ApplicationDatabaseMigrationManager;
use App\Support\ApplicationDatabaseBootstrap;
use App\Support\ApplicationDatabaseMigrationStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ApplicationDatabaseMigrationWorkflowTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/application-database-workflow-'.bin2hex(random_bytes(8)));
        mkdir($this->directory, 0700, true);
        config([
            'database.control_metadata.mode' => ApplicationDatabaseBootstrap::ManagedMode,
            'database.control_metadata.path' => $this->directory.'/active.enc',
            'database.control_metadata.migration_directory' => $this->directory.'/plans',
            'database.control_metadata.migration_fence_path' => $this->directory.'/migration.fence',
            'queue.default' => 'sync',
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('control');
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_cutover_requires_restart_finalization_and_rollback_preserves_new_data(): void
    {
        $source = $this->sqlitePayload($this->directory.'/source.sqlite');
        $destination = $this->sqlitePayload($this->directory.'/destination.sqlite');
        touch($source['database']);
        $this->activateForTest($source);
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'control',
            '--force' => true,
            '--no-interaction' => true,
        ]));
        DB::table('roles')->insert([
            'id' => 7, 'name' => 'Workflow Role', 'slug' => 'workflow-role', 'description' => null,
            'is_admin' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $manager = app(ApplicationDatabaseMigrationManager::class);
        $planned = $manager->plan($destination);
        $copied = $manager->migrate($planned['id'], 0);
        $this->assertSame('copied', $copied['status']);
        $this->assertFileExists($this->directory.'/migration.fence');
        $this->assertTrue(app(QueueManager::class)->isPaused('sync', 'default'));

        $verified = $manager->verify($planned['id']);
        $this->assertSame('verified', $verified['status']);
        $pending = $manager->activate($planned['id'], false);
        $this->assertSame('activation_pending_restart', $pending['status']);

        $this->expectExceptionMessage('destination is not active yet');
        $manager->activate($planned['id'], true);
    }

    public function test_finalized_cutover_can_copy_new_destination_data_back_before_rollback(): void
    {
        $source = $this->sqlitePayload($this->directory.'/source.sqlite');
        $destination = $this->sqlitePayload($this->directory.'/destination.sqlite');
        touch($source['database']);
        $this->activateForTest($source);
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'control',
            '--force' => true,
            '--no-interaction' => true,
        ]));

        $manager = app(ApplicationDatabaseMigrationManager::class);
        $state = $manager->plan($destination);
        $manager->migrate($state['id'], 0);
        $manager->verify($state['id']);
        $manager->activate($state['id'], false);
        $this->activateForTest($destination);
        $active = $manager->activate($state['id'], true);
        $this->assertSame('active', $active['status']);
        $this->assertFileDoesNotExist($this->directory.'/migration.fence');
        $this->assertFalse(app(QueueManager::class)->isPaused('sync', 'default'));

        DB::table('application_settings')->insert([
            'key' => 'created-after-cutover',
            'value' => 'still-encrypted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rollback = $manager->rollback($state['id'], false);
        $this->assertSame('rollback_pending_restart', $rollback['status']);
        $this->activateForTest($source);
        $rolledBack = $manager->rollback($state['id'], true);

        $this->assertSame('rolled_back', $rolledBack['status']);
        $this->assertSame('still-encrypted', DB::table('application_settings')->where('key', 'created-after-cutover')->value('value'));
        $this->assertFileDoesNotExist($this->directory.'/migration.fence');
    }

    public function test_copy_failure_keeps_the_source_active_and_releases_the_fence(): void
    {
        $source = $this->sqlitePayload($this->directory.'/source.sqlite');
        $destination = $this->sqlitePayload($this->directory.'/destination.sqlite');
        touch($source['database']);
        $this->activateForTest($source);
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'control',
            '--force' => true,
            '--no-interaction' => true,
        ]));

        $manager = app(ApplicationDatabaseMigrationManager::class);
        $state = $manager->plan($destination);
        touch($destination['database']);
        config(['database.connections.tampered_destination' => ApplicationDatabaseBootstrap::connection($destination, base_path())]);
        DB::purge('tampered_destination');
        Schema::connection('tampered_destination')->create('unexpected_table', function (Blueprint $table): void {
            $table->id();
        });
        DB::purge('tampered_destination');

        try {
            $manager->migrate($state['id'], 0);
            $this->fail('A destination changed after planning must fail safely.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('destination database must be empty', $exception->getMessage());
        }

        $active = ApplicationDatabaseBootstrap::read(
            $this->directory.'/active.enc',
            (string) config('app.key'),
            (string) config('app.cipher'),
        );
        $this->assertSame(ApplicationDatabaseBootstrap::fingerprint($source), ApplicationDatabaseBootstrap::fingerprint($active));
        $this->assertSame('failed', app(ApplicationDatabaseMigrationStore::class)->read($state['id'])['status']);
        $this->assertFileDoesNotExist($this->directory.'/migration.fence');
    }

    public function test_an_interrupted_copy_can_resume_while_it_owns_the_fence(): void
    {
        $source = $this->sqlitePayload($this->directory.'/source.sqlite');
        $destination = $this->sqlitePayload($this->directory.'/destination.sqlite');
        touch($source['database']);
        $this->activateForTest($source);
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'control',
            '--force' => true,
            '--no-interaction' => true,
        ]));

        $manager = app(ApplicationDatabaseMigrationManager::class);
        $state = $manager->plan($destination);
        $copied = $manager->migrate($state['id'], 0);
        app(ApplicationDatabaseMigrationStore::class)->update($state['id'], function (array $state): array {
            $state['status'] = 'copying';

            return $state;
        });

        $resumed = $manager->migrate($state['id'], 0);

        $this->assertSame('copied', $resumed['status']);
        $this->assertSame($copied['tables'], $resumed['tables']);
        $this->assertFileExists($this->directory.'/migration.fence');
    }

    /** @param array<string, mixed> $payload */
    private function activateForTest(array $payload): void
    {
        ApplicationDatabaseBootstrap::write(
            $payload,
            $this->directory.'/active.enc',
            (string) config('app.key'),
            (string) config('app.cipher'),
        );
        config([
            'database.connections.control' => ApplicationDatabaseBootstrap::connection($payload, base_path()),
            'database.control_metadata.configured' => true,
            'database.control_metadata.fingerprint' => ApplicationDatabaseBootstrap::fingerprint($payload),
        ]);
        DB::purge('control');
    }

    /** @return array<string, mixed> */
    private function sqlitePayload(string $path): array
    {
        return [
            'version' => ApplicationDatabaseBootstrap::CurrentVersion,
            'driver' => 'sqlite',
            'database' => $path,
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL',
            'synchronous' => 'FULL',
            'transaction_mode' => 'IMMEDIATE',
        ];
    }
}
