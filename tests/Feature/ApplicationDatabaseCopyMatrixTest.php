<?php

namespace Tests\Feature;

use App\Models\NativeProxyLease;
use App\Services\ApplicationDatabaseCopyEngine;
use App\Support\ApplicationDatabaseBootstrap;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationDatabaseCopyMatrixTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/application-database-copy-matrix-'.bin2hex(random_bytes(8)));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (['matrix_source', 'matrix_destination'] as $connection) {
            DB::purge($connection);
            config()->offsetUnset('database.connections.'.$connection);
        }

        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function driverPairs(): iterable
    {
        yield 'SQLite to PostgreSQL' => ['sqlite', 'pgsql'];
        yield 'SQLite to MySQL' => ['sqlite', 'mysql'];
        yield 'PostgreSQL to SQLite' => ['pgsql', 'sqlite'];
        yield 'MySQL to SQLite' => ['mysql', 'sqlite'];
        yield 'PostgreSQL to MySQL' => ['pgsql', 'mysql'];
        yield 'MySQL to PostgreSQL' => ['mysql', 'pgsql'];
    }

    #[DataProvider('driverPairs')]
    public function test_representative_data_round_trips_between_supported_drivers(string $sourceDriver, string $destinationDriver): void
    {
        $originalDefault = (string) config('database.default');
        $this->skipWhenNetworkDriverIsUnavailable($sourceDriver);
        $this->skipWhenNetworkDriverIsUnavailable($destinationDriver);
        $source = $this->payload($sourceDriver, 'source');
        $destination = $this->payload($destinationDriver, 'destination');
        $this->reset($source, 'matrix_source');
        $this->reset($destination, 'matrix_destination');

        try {
            $this->configure('matrix_source', $source);
            $this->assertSame(0, Artisan::call('migrate', [
                '--database' => 'matrix_source',
                '--force' => true,
                '--no-interaction' => true,
            ]));
            $nativeLease = $this->seedSource();

            $copied = app(ApplicationDatabaseCopyEngine::class)->copy(
                $source,
                $destination,
                [],
                static function (): void {},
            );
            $verified = app(ApplicationDatabaseCopyEngine::class)->verify($source, $destination, $copied);
            $this->configure('matrix_destination', $destination);

            $this->assertSame(
                $this->integrityResults($copied),
                $this->integrityResults($verified),
            );
            $this->assertSame(
                97,
                DB::connection('matrix_destination')
                    ->table('users')
                    ->where('email', 'matrix@example.test')
                    ->value('id'),
            );
            $this->assertSame(
                'eyJpdiI6Im1hdHJpeC1jaXBoZXJ0ZXh0In0=',
                DB::connection('matrix_destination')->table('application_settings')->value('value'),
            );
            $this->assertJsonStringEqualsJsonString(
                '{"array":[1,null,true],"label":"matrix"}',
                (string) DB::connection('matrix_destination')->table('audit_logs')->value('metadata'),
            );
            $this->assertSame(
                $nativeLease['ciphertext'],
                DB::connection('matrix_destination')
                    ->table('native_proxy_leases')
                    ->where('id', $nativeLease['id'])
                    ->value('protocol_auth_secret'),
            );

            DB::setDefaultConnection('matrix_destination');
            $this->assertSame(
                'matrix-native-secret',
                NativeProxyLease::query()->findOrFail($nativeLease['id'])->protocol_auth_secret,
            );
            $maximumUserId = (int) DB::connection('matrix_destination')->table('users')->max('id');
            $nextUserId = DB::connection('matrix_destination')->table('users')->insertGetId([
                'role_id' => 89,
                'name' => 'Post-migration User',
                'email' => 'post-migration@example.test',
                'password' => 'password-hash',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->assertGreaterThan($maximumUserId, $nextUserId);

            $rolledBack = app(ApplicationDatabaseCopyEngine::class)->copy(
                $destination,
                $source,
                [],
                static function (): void {},
                true,
                true,
            );
            $this->assertSame(
                $this->integrityResults($rolledBack),
                $this->integrityResults(app(ApplicationDatabaseCopyEngine::class)->verify(
                    $destination,
                    $source,
                    $rolledBack,
                )),
            );
            $this->assertSame(
                $nextUserId,
                DB::connection('matrix_source')->table('users')->where('email', 'post-migration@example.test')->value('id'),
            );
        } finally {
            DB::setDefaultConnection($originalDefault);
            $this->reset($source, 'matrix_source');
            $this->reset($destination, 'matrix_destination');
        }
    }

    /** @return array{id: string, ciphertext: string} */
    private function seedSource(): array
    {
        $now = '2026-09-03 10:20:30';
        $database = DB::connection('matrix_source');
        $database->table('roles')->insert([
            'id' => 89, 'name' => 'Matrix Role', 'slug' => 'matrix-role', 'description' => null,
            'is_admin' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $database->table('users')->insert([
            'id' => 97, 'role_id' => 89, 'name' => 'Matrix User', 'email' => 'matrix@example.test',
            'email_verified_at' => null, 'password' => 'password-hash', 'remember_token' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $database->table('application_settings')->insert([
            'id' => 59, 'key' => 'matrix-encrypted', 'value' => 'eyJpdiI6Im1hdHJpeC1jaXBoZXJ0ZXh0In0=',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $database->table('audit_logs')->insert([
            'id' => 61, 'actor_id' => 97, 'action' => 'matrix.tested', 'auditable_type' => null,
            'auditable_id' => null, 'ip_address' => null, 'user_agent' => null,
            'metadata' => '{"label":"matrix","array":[1,null,true]}', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $originalDefault = (string) config('database.default');
        DB::setDefaultConnection('matrix_source');

        try {
            $lease = NativeProxyLease::factory()->revoked()->create([
                'protocol_auth_secret' => 'matrix-native-secret',
                'expires_at' => now()->subHour(),
            ]);
            $ciphertext = (string) $database->table('native_proxy_leases')
                ->where('id', $lease->getKey())
                ->value('protocol_auth_secret');

            return ['id' => (string) $lease->getKey(), 'ciphertext' => $ciphertext];
        } finally {
            DB::setDefaultConnection($originalDefault);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $tables
     * @return array<string, array{status: mixed, rows: mixed, hash: mixed}>
     */
    private function integrityResults(array $tables): array
    {
        return collect($tables)->map(fn (array $table): array => [
            'status' => $table['status'] ?? null,
            'rows' => $table['rows'] ?? null,
            'hash' => $table['hash'] ?? null,
        ])->all();
    }

    /** @return array<string, mixed> */
    private function payload(string $driver, string $suffix): array
    {
        if ($driver === 'sqlite') {
            return [
                'version' => ApplicationDatabaseBootstrap::CurrentVersion,
                'driver' => 'sqlite',
                'database' => $this->directory.'/'.$suffix.'.sqlite',
                'foreign_key_constraints' => true,
                'busy_timeout' => 5000,
                'journal_mode' => 'WAL',
                'synchronous' => 'FULL',
                'transaction_mode' => 'IMMEDIATE',
            ];
        }

        if ($driver === 'pgsql') {
            return [
                'version' => ApplicationDatabaseBootstrap::CurrentVersion,
                'driver' => 'pgsql',
                'host' => (string) getenv('CONTROL_DATABASE_POSTGRES_HOST'),
                'port' => 5432,
                'database' => 'crucible_control_test',
                'username' => 'crucible',
                'password' => 'crucible_test',
                'pgsql_sslmode' => 'disable',
            ];
        }

        return [
            'version' => ApplicationDatabaseBootstrap::CurrentVersion,
            'driver' => 'mysql',
            'host' => (string) getenv('CONTROL_DATABASE_MYSQL_HOST'),
            'port' => 3306,
            'database' => 'crucible_control_test',
            'username' => 'crucible',
            'password' => 'crucible_test',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function reset(array $payload, string $connection): void
    {
        if ($payload['driver'] === 'sqlite') {
            DB::purge($connection);
            $path = (string) $payload['database'];

            if (is_file($path)) {
                unlink($path);
            }

            return;
        }

        $this->configure($connection, $payload);
        Schema::connection($connection)->dropAllTables();
        DB::purge($connection);
    }

    /** @param array<string, mixed> $payload */
    private function configure(string $connection, array $payload): void
    {
        config(['database.connections.'.$connection => ApplicationDatabaseBootstrap::connection($payload, base_path())]);
        DB::purge($connection);
    }

    private function skipWhenNetworkDriverIsUnavailable(string $driver): void
    {
        if ($driver === 'pgsql' && ! getenv('CONTROL_DATABASE_POSTGRES_HOST')) {
            $this->markTestSkipped('The PostgreSQL application-database matrix service is not enabled.');
        }

        if ($driver === 'mysql' && ! getenv('CONTROL_DATABASE_MYSQL_HOST')) {
            $this->markTestSkipped('The MySQL application-database matrix service is not enabled.');
        }
    }
}
