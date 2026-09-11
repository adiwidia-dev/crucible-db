<?php

namespace Tests\Feature;

use App\Exceptions\ApplicationDatabaseMigrationOperationException;
use App\Services\ApplicationDatabaseCopyEngine;
use App\Services\ApplicationDatabaseMigrationFence;
use App\Services\ApplicationDatabaseMigrationManager;
use App\Support\ApplicationDatabaseBootstrap;
use App\Support\ApplicationDatabaseMigrationStore;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ApplicationDatabaseMigrationFaultTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/application-database-fault-'.bin2hex(random_bytes(8)));
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

    public function test_tampered_encrypted_migration_state_is_rejected(): void
    {
        $store = app(ApplicationDatabaseMigrationStore::class);
        $state = $store->create([
            'status' => 'planned',
            'source' => [],
            'destination' => [],
        ]);
        file_put_contents($this->directory.'/plans/'.$state['id'].'.enc', 'tampered-ciphertext');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be decrypted');
        $store->read((string) $state['id']);
    }

    public function test_invalid_network_credentials_do_not_create_a_plan_or_engage_the_fence(): void
    {
        $source = $this->prepareSource();

        try {
            app(ApplicationDatabaseMigrationManager::class)->plan([
                'version' => ApplicationDatabaseBootstrap::CurrentVersion,
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'unreachable',
                'username' => 'invalid',
                'password' => 'invalid',
            ]);
            $this->fail('Unreachable credentials must fail during planning.');
        } catch (\Throwable) {
            $this->assertNull(app(ApplicationDatabaseMigrationStore::class)->currentId());
            $this->assertFalse(app(ApplicationDatabaseMigrationFence::class)->isActive());
            $this->assertSame(
                ApplicationDatabaseBootstrap::fingerprint($source),
                config('database.control_metadata.fingerprint'),
            );
        }
    }

    public function test_a_concurrent_copy_is_rejected_before_it_can_change_or_release_the_fence(): void
    {
        $this->prepareSource();
        $manager = app(ApplicationDatabaseMigrationManager::class);
        $state = $manager->plan($this->sqlitePayload($this->directory.'/destination.sqlite'));
        $planId = (string) $state['id'];
        $fence = app(ApplicationDatabaseMigrationFence::class);
        $fence->engage($planId);

        $fence->runExclusive($planId, function () use ($manager, $planId): void {
            try {
                $manager->migrate($planId, 0);
                $this->fail('A concurrent copy must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('already running', $exception->getMessage());
            }
        });

        $this->assertSame('planned', app(ApplicationDatabaseMigrationStore::class)->read($planId)['status']);
        $this->assertTrue($fence->isActive());
    }

    public function test_a_genuinely_partial_copy_resumes_from_verified_tables(): void
    {
        $source = $this->prepareSource();
        $destination = $this->sqlitePayload($this->directory.'/destination.sqlite');
        $manager = app(ApplicationDatabaseMigrationManager::class);
        $state = $manager->plan($destination);
        $partialTables = [];

        try {
            app(ApplicationDatabaseCopyEngine::class)->copy(
                $source,
                $destination,
                [],
                function (string $table, array $result) use (&$partialTables): void {
                    $partialTables[$table] = $result;
                    throw new RuntimeException('simulated process interruption');
                },
            );
            $this->fail('The injected interruption should stop the first copy.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated process interruption', $exception->getMessage());
        }

        app(ApplicationDatabaseMigrationStore::class)->update((string) $state['id'], function (array $state) use ($partialTables): array {
            $state['status'] = 'copying';
            $state['tables'] = $partialTables;

            return $state;
        });

        $resumed = $manager->migrate((string) $state['id'], 0);

        $this->assertSame('copied', $resumed['status']);
        $this->assertGreaterThan(count($partialTables), count($resumed['tables']));
        $this->assertSame(array_key_first($partialTables), array_key_first($resumed['tables']));
    }

    public function test_verification_detects_source_changes_after_copy_and_keeps_the_fence(): void
    {
        $this->prepareSource();
        $manager = app(ApplicationDatabaseMigrationManager::class);
        $state = $manager->plan($this->sqlitePayload($this->directory.'/destination.sqlite'));
        $manager->migrate((string) $state['id'], 0);
        DB::table('application_settings')->insert([
            'key' => 'changed-after-copy',
            'value' => 'ciphertext',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $manager->verify((string) $state['id']);
            $this->fail('Source mutation after copy must fail verification.');
        } catch (ApplicationDatabaseMigrationOperationException $exception) {
            $this->assertStringContainsString('Reference:', $exception->getMessage());
            $this->assertStringNotContainsString('application_settings', $exception->getMessage());
        }

        $stored = app(ApplicationDatabaseMigrationStore::class)->read((string) $state['id']);
        $this->assertSame('copied', $stored['status']);
        $failureEvent = collect($stored['events'])->firstWhere('event', 'verification_failed');
        $this->assertIsArray($failureEvent);
        $this->assertSame('verification', $failureEvent['phase']);
        $this->assertStringNotContainsString('application_settings', $failureEvent['message']);
        $this->assertTrue(app(ApplicationDatabaseMigrationFence::class)->isActive());
    }

    /** @return array<string, mixed> */
    private function prepareSource(): array
    {
        $source = $this->sqlitePayload($this->directory.'/source.sqlite');
        touch($source['database']);
        ApplicationDatabaseBootstrap::write(
            $source,
            $this->directory.'/active.enc',
            (string) config('app.key'),
            (string) config('app.cipher'),
        );
        config([
            'database.connections.control' => ApplicationDatabaseBootstrap::connection($source, base_path()),
            'database.control_metadata.configured' => true,
            'database.control_metadata.fingerprint' => ApplicationDatabaseBootstrap::fingerprint($source),
        ]);
        DB::purge('control');
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'control',
            '--force' => true,
            '--no-interaction' => true,
        ]));

        return $source;
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
