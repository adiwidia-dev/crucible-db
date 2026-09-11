<?php

namespace Tests\Unit;

use App\Support\ApplicationDatabaseBootstrap;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ApplicationDatabaseBootstrapTest extends TestCase
{
    private string $directory;

    private string $path;

    private string $key = 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/crucible-bootstrap-'.bin2hex(random_bytes(8));
        $this->path = $this->directory.'/application-database.enc';
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_encrypts_and_round_trips_a_managed_configuration(): void
    {
        $payload = [
            'version' => ApplicationDatabaseBootstrap::CurrentVersion,
            'driver' => 'pgsql',
            'host' => 'postgres.internal',
            'port' => 5432,
            'database' => 'crucible',
            'username' => 'crucible',
            'password' => 'very-secret-password',
            'pgsql_sslmode' => 'verify-full',
        ];

        ApplicationDatabaseBootstrap::write($payload, $this->path, $this->key, 'AES-256-CBC');

        $contents = file_get_contents($this->path);

        $this->assertIsString($contents);
        $this->assertStringNotContainsString('very-secret-password', $contents);
        $this->assertStringNotContainsString('postgres.internal', $contents);
        $this->assertSame($payload, ApplicationDatabaseBootstrap::read($this->path, $this->key, 'AES-256-CBC'));
        $this->assertSame(0600, fileperms($this->path) & 0777);
    }

    public function test_it_fails_closed_when_the_encrypted_file_is_tampered_with(): void
    {
        ApplicationDatabaseBootstrap::write([
            'version' => ApplicationDatabaseBootstrap::CurrentVersion,
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], $this->path, $this->key, 'AES-256-CBC');

        file_put_contents($this->path, 'tampered'.file_get_contents($this->path));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be decrypted');

        ApplicationDatabaseBootstrap::read($this->path, $this->key, 'AES-256-CBC');
    }

    public function test_it_rejects_an_unknown_driver(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be sqlite, mysql, or pgsql');

        ApplicationDatabaseBootstrap::connection([
            'driver' => 'sqlsrv',
        ], '/application');
    }

    public function test_it_builds_driver_specific_connection_configuration(): void
    {
        $postgres = ApplicationDatabaseBootstrap::connection([
            'driver' => 'pgsql',
            'host' => 'postgres',
            'port' => 5432,
            'database' => 'crucible',
            'username' => 'app',
            'password' => 'secret',
            'pgsql_sslmode' => 'require',
        ], '/application');
        $mysql = ApplicationDatabaseBootstrap::connection([
            'driver' => 'mysql',
            'host' => 'mysql',
            'port' => 3306,
            'database' => 'crucible',
            'username' => 'app',
            'password' => 'secret',
        ], '/application');
        $sqlite = ApplicationDatabaseBootstrap::connection([
            'driver' => 'sqlite',
            'database' => 'storage/database/crucible.sqlite',
        ], '/application');

        $this->assertSame('require', $postgres['sslmode']);
        $this->assertSame('utf8mb4_unicode_ci', $mysql['collation']);
        $this->assertSame('/application/storage/database/crucible.sqlite', $sqlite['database']);
    }

    public function test_it_resolves_managed_configuration_over_the_fallback_connection(): void
    {
        $payload = [
            'version' => ApplicationDatabaseBootstrap::CurrentVersion,
            'driver' => 'mysql',
            'host' => 'mysql.internal',
            'port' => 3306,
            'database' => 'crucible',
            'username' => 'app',
            'password' => 'secret',
        ];
        ApplicationDatabaseBootstrap::write($payload, $this->path, $this->key, 'AES-256-CBC');

        $resolved = ApplicationDatabaseBootstrap::resolve(
            '/application',
            ApplicationDatabaseBootstrap::ManagedMode,
            $this->path,
            ['driver' => 'sqlite', 'database' => ':memory:'],
            $this->key,
            'AES-256-CBC',
        );

        $this->assertTrue($resolved['configured']);
        $this->assertSame('mysql', $resolved['connection']['driver']);
        $this->assertSame(ApplicationDatabaseBootstrap::fingerprint($payload), $resolved['fingerprint']);
    }

    public function test_environment_mode_ignores_a_managed_configuration_file(): void
    {
        ApplicationDatabaseBootstrap::write([
            'version' => ApplicationDatabaseBootstrap::CurrentVersion,
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], $this->path, $this->key, 'AES-256-CBC');
        file_put_contents($this->path, 'not encrypted');
        $fallback = ['driver' => 'sqlite', 'database' => ':memory:'];

        $resolved = ApplicationDatabaseBootstrap::resolve(
            '/application',
            ApplicationDatabaseBootstrap::EnvironmentMode,
            $this->path,
            $fallback,
            $this->key,
            'AES-256-CBC',
        );

        $this->assertSame($fallback, $resolved['connection']);
        $this->assertTrue($resolved['configured']);
    }
}
