<?php

namespace Tests\Feature;

use App\Services\ApplicationDatabaseCopyEngine;
use App\Support\ApplicationDatabaseBootstrap;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplicationDatabaseCopyEngineTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/application-database-copy-'.bin2hex(random_bytes(8)));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        DB::purge('copy_test_source');
        DB::purge('copy_test_destination');
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_copies_and_verifies_durable_data_without_transforming_ciphertext(): void
    {
        $source = $this->sqlitePayload($this->directory.'/source.sqlite');
        $destination = $this->sqlitePayload($this->directory.'/destination.sqlite');
        touch($source['database']);
        $this->configure('copy_test_source', $source);

        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'copy_test_source',
            '--force' => true,
            '--no-interaction' => true,
        ]));

        $now = '2026-09-03 10:20:30';
        DB::connection('copy_test_source')->table('roles')->insert([
            'id' => 41, 'name' => 'Migration Admin', 'slug' => 'migration-admin',
            'description' => null, 'is_admin' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::connection('copy_test_source')->table('users')->insert([
            'id' => 73, 'role_id' => 41, 'name' => 'Cipher User', 'email' => 'cipher@example.test',
            'email_verified_at' => null, 'password' => 'password-hash', 'remember_token' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::connection('copy_test_source')->table('application_settings')->insert([
            'id' => 19, 'key' => 'encrypted-example', 'value' => 'eyJpdiI6ImFscmVhZHktZW5jcnlwdGVkIn0=',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::connection('copy_test_source')->table('audit_logs')->insert([
            'id' => 101, 'actor_id' => 73, 'action' => 'migration.tested', 'auditable_type' => null,
            'auditable_id' => null, 'ip_address' => null, 'user_agent' => null,
            'metadata' => '{"nested":{"nullable":null,"enabled":true}}', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::connection('copy_test_source')->table('sessions')->insert([
            'id' => 'ephemeral-session', 'user_id' => 73, 'ip_address' => null, 'user_agent' => null,
            'payload' => 'discarded', 'last_activity' => 1,
        ]);

        $progress = [];
        $copied = app(ApplicationDatabaseCopyEngine::class)->copy(
            $source,
            $destination,
            [],
            function (string $table) use (&$progress): void {
                $progress[] = $table;
            },
        );

        $this->configure('copy_test_destination', $destination);
        $this->assertContains('native_proxy_leases', $progress);
        $this->assertSame(1, $copied['users']['rows']);
        $this->assertSame(73, DB::connection('copy_test_destination')->table('users')->value('id'));
        $this->assertSame(
            'eyJpdiI6ImFscmVhZHktZW5jcnlwdGVkIn0=',
            DB::connection('copy_test_destination')->table('application_settings')->value('value'),
        );
        $this->assertJsonStringEqualsJsonString(
            '{"nested":{"nullable":null,"enabled":true}}',
            (string) DB::connection('copy_test_destination')->table('audit_logs')->value('metadata'),
        );
        $this->assertSame(0, DB::connection('copy_test_destination')->table('sessions')->count());
        $verified = app(ApplicationDatabaseCopyEngine::class)->verify($source, $destination, $copied);

        foreach ($copied as $table => $result) {
            $this->assertSame($result['status'], $verified[$table]['status']);
            $this->assertSame($result['rows'], $verified[$table]['rows']);
            $this->assertSame($result['hash'], $verified[$table]['hash']);
        }
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

    /** @param array<string, mixed> $payload */
    private function configure(string $connection, array $payload): void
    {
        config(['database.connections.'.$connection => ApplicationDatabaseBootstrap::connection($payload, base_path())]);
        DB::purge($connection);
    }
}
