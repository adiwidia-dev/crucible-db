<?php

namespace Tests\Feature;

use App\Enums\ApplicationDatabaseDriver;
use App\Services\ApplicationDatabaseManager;
use App\Support\ApplicationDatabaseBootstrap;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApplicationDatabaseProvisioningMatrixTest extends TestCase
{
    use RefreshDatabase;

    private string $configurationDirectory;

    private string $configurationPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurationDirectory = storage_path('framework/testing/provisioning-matrix-'.bin2hex(random_bytes(8)));
        $this->configurationPath = $this->configurationDirectory.'/configuration.enc';
        config([
            'database.control_metadata.mode' => ApplicationDatabaseBootstrap::ManagedMode,
            'database.control_metadata.path' => $this->configurationPath,
            'database.control_metadata.configured' => false,
            'database.control_metadata.fingerprint' => null,
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->configurationDirectory);

        parent::tearDown();
    }

    public function test_it_provisions_an_empty_postgresql_application_database(): void
    {
        $host = getenv('CONTROL_DATABASE_POSTGRES_HOST');

        if (! is_string($host) || $host === '') {
            $this->markTestSkipped('The PostgreSQL application-database matrix service is not enabled.');
        }

        $this->assertProvisioningSucceeds([
            'driver' => ApplicationDatabaseDriver::PostgreSql->value,
            'host' => $host,
            'port' => 5432,
            'database' => 'crucible_control_test',
            'username' => 'crucible',
            'password' => 'crucible_test',
            'pgsql_sslmode' => 'disable',
        ]);
    }

    public function test_it_provisions_an_empty_mysql_application_database(): void
    {
        $host = getenv('CONTROL_DATABASE_MYSQL_HOST');

        if (! is_string($host) || $host === '') {
            $this->markTestSkipped('The MySQL application-database matrix service is not enabled.');
        }

        $this->assertProvisioningSucceeds([
            'driver' => ApplicationDatabaseDriver::MySql->value,
            'host' => $host,
            'port' => 3306,
            'database' => 'crucible_control_test',
            'username' => 'crucible',
            'password' => 'crucible_test',
        ]);
    }

    public function test_it_refuses_to_overwrite_a_nonempty_postgresql_database(): void
    {
        $host = getenv('CONTROL_DATABASE_POSTGRES_HOST');

        if (! is_string($host) || $host === '') {
            $this->markTestSkipped('The PostgreSQL application-database matrix service is not enabled.');
        }

        $payload = [
            'driver' => ApplicationDatabaseDriver::PostgreSql->value,
            'host' => $host,
            'port' => 5432,
            'database' => 'crucible_control_test',
            'username' => 'crucible',
            'password' => 'crucible_test',
            'pgsql_sslmode' => 'disable',
        ];
        $this->resetMatrixDatabase($payload);
        $this->configureMatrixConnection($payload);
        Schema::connection('application_database_matrix')->create('existing_table', function ($table): void {
            $table->id();
        });
        DB::purge('application_database_matrix');

        try {
            app(ApplicationDatabaseManager::class)->provision($payload);
            $this->fail('Provisioning should reject a database that already has tables.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('database', $exception->errors());
            $this->assertStringContainsString('dedicated empty database', $exception->errors()['database'][0]);
            $this->assertFileDoesNotExist($this->configurationPath);
        } finally {
            $this->resetMatrixDatabase($payload);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertProvisioningSucceeds(array $payload): void
    {
        $this->resetMatrixDatabase($payload);

        try {
            app(ApplicationDatabaseManager::class)->provision($payload);

            $this->assertFileExists($this->configurationPath);
            $contents = file_get_contents($this->configurationPath);
            $this->assertIsString($contents);
            $this->assertStringNotContainsString('crucible_test', $contents);
            $this->assertTrue(app(ApplicationDatabaseManager::class)->requiresRestart());

            $this->configureMatrixConnection($payload);
            $schema = Schema::connection('application_database_matrix');
            $this->assertTrue($schema->hasTable('users'));
            $this->assertTrue($schema->hasTable('native_proxy_leases'));
            $this->assertTrue($schema->hasTable('native_proxy_connections'));
        } finally {
            DB::purge('application_database_matrix');
            $this->resetMatrixDatabase($payload);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resetMatrixDatabase(array $payload): void
    {
        $this->assertStringEndsWith('_test', (string) $payload['database']);
        $this->configureMatrixConnection($payload);
        Schema::connection('application_database_matrix')->dropAllTables();
        DB::purge('application_database_matrix');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function configureMatrixConnection(array $payload): void
    {
        config([
            'database.connections.application_database_matrix' => ApplicationDatabaseBootstrap::connection(
                $payload,
                base_path(),
            ),
        ]);
        DB::purge('application_database_matrix');

    }
}
