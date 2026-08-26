<?php

namespace Tests\Feature;

use App\Enums\DatabaseDriver;
use App\Enums\DatabaseTlsMode;
use App\Enums\QueryType;
use App\Models\DatabaseConnection;
use App\Services\ApplicationSettings;
use App\Services\DatabaseQueryExecutor;
use App\Services\DatabaseSchemaBrowser;
use App\Services\QueryGuard;
use Closure;
use Generator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use Pdo\Mysql;
use RuntimeException;
use Tests\TestCase;

class QueryExecutionSecurityTest extends TestCase
{
    public function test_disabled_statement_families_use_the_current_safety_policy_copy(): void
    {
        $guard = app(QueryGuard::class);

        try {
            $guard->validateExecutable('DROP TABLE users');
            $this->fail('Destructive DDL should be blocked.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['DROP TABLE statements are disabled by the workspace administrator.'],
                $exception->errors()['sql'],
            );
        }
    }

    public function test_explain_analyze_is_rejected_before_classification(): void
    {
        $guard = app(QueryGuard::class);

        foreach ([
            'EXPLAIN ANALYZE DELETE FROM users',
            'EXPLAIN (ANALYZE, BUFFERS) UPDATE users SET name = \'attacker\'',
            'explain (format json, analyze true) insert into users (name) values (\'attacker\')',
            'EXPLAIN /* hidden option */ ANALYZE DELETE FROM users',
            '/* explain wrapper */ EXPLAIN ANALYZE DELETE FROM users',
        ] as $sql) {
            try {
                $guard->classify($sql);
                $this->fail("Expected [{$sql}] to be rejected.");
            } catch (ValidationException $exception) {
                $this->assertSame(
                    ['EXPLAIN ANALYZE is not supported because it can execute the explained statement.'],
                    $exception->errors()['sql'],
                );
            }
        }

        $this->assertSame(QueryType::Read, $guard->classify("EXPLAIN SELECT 'analyze' AS operation"));
        $this->assertSame(QueryType::Read, $guard->classify('EXPLAIN SELECT "analyze" FROM operations'));
    }

    public function test_cte_led_update_is_classified_as_a_write_statement(): void
    {
        $sql = <<<'SQL'
WITH truth AS (
    SELECT hub_code, COUNT(*)::int AS active_count
    FROM riders
    WHERE on_demand_order_status = 'ACTIVE'
    GROUP BY hub_code
)
UPDATE on_demand_order_daily_summaries AS summary
SET total_current_active = truth.active_count
FROM truth
WHERE summary.hub_code = truth.hub_code
SQL;

        $guard = app(QueryGuard::class);

        $this->assertSame(QueryType::Write, $guard->classify($sql));
        $this->assertStringStartsWith('UPDATE', $guard->topLevelExecutableSql($sql));
    }

    public function test_emergency_fallback_treats_unknown_deployment_sql_as_write_but_keeps_session_and_transaction_guards(): void
    {
        $settings = Mockery::mock(ApplicationSettings::class);
        $settings->shouldReceive('allowsEmergencySqlFallback')->andReturnTrue();

        $guard = new QueryGuard($settings);
        $sql = 'CREATE INDEX users_email_index ON users (email)';

        $this->assertSame(QueryType::Write, $guard->classify($sql));
        $this->assertTrue($guard->usesEmergencySqlFallback($sql));

        try {
            $guard->validateSessionExecutable($sql);
            $this->fail('Query Access sessions should not use the emergency SQL fallback.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['This SQL statement is not supported in a query access session.'],
                $exception->errors()['sql'],
            );
        }

        try {
            $guard->validateExecutable('BEGIN');
            $this->fail('Transaction-control SQL should remain blocked.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Transaction-control SQL statements are blocked. Submit each executable statement in its own batch position.'],
                $exception->errors()['sql'],
            );
        }

        foreach ([
            'CREATE EXTENSION pg_trgm',
            'CREATE FUNCTION refresh_materialized_views() RETURNS void AS $$ SELECT 1; $$ LANGUAGE sql',
            'CREATE TRIGGER audit_customer_update BEFORE UPDATE ON customers EXECUTE FUNCTION audit_customer_update()',
            'VACUUM users',
            'CALL refresh_materialized_views()',
        ] as $blockedSql) {
            try {
                $guard->validateExecutable($blockedSql);
                $this->fail("Expected [{$blockedSql}] to remain blocked.");
            } catch (ValidationException $exception) {
                $this->assertSame(
                    ['Administrative, file, security-management, and procedural SQL statements are blocked.'],
                    $exception->errors()['sql'],
                );
            }
        }
    }

    public function test_postgresql_reads_run_in_a_read_only_transaction(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('beginTransaction')->once()->ordered();
        $connection->shouldReceive('statement')->with('SET TRANSACTION READ ONLY')->once()->ordered()->andReturnTrue();
        $connection->shouldReceive('cursor')->with('select 1 as value')->once()->ordered()->andReturn($this->rows());
        $connection->shouldReceive('rollBack')->once()->ordered();

        $this->mockDatabaseFacade($connection, 901);

        $result = app(DatabaseQueryExecutor::class)->execute(
            $this->databaseConnection(901, DatabaseDriver::PostgreSql),
            'select 1 as value',
            QueryType::Read,
        );

        $this->assertSame(1, $result['row_count']);
        $this->assertSame([['value' => 1]], $result['sample_rows']);
    }

    public function test_mysql_read_only_mode_is_set_before_the_transaction_starts(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('statement')->with('SET TRANSACTION READ ONLY')->once()->ordered()->andReturnTrue();
        $connection->shouldReceive('beginTransaction')->once()->ordered();
        $connection->shouldReceive('cursor')->with('select 1 as value')->once()->ordered()->andReturn($this->rows());
        $connection->shouldReceive('rollBack')->once()->ordered();

        $this->mockDatabaseFacade($connection, 902);

        app(DatabaseQueryExecutor::class)->execute(
            $this->databaseConnection(902, DatabaseDriver::MySql),
            'select 1 as value',
            QueryType::Read,
        );
    }

    public function test_read_only_transaction_is_rolled_back_when_the_query_fails(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('beginTransaction')->once()->ordered();
        $connection->shouldReceive('statement')->with('SET TRANSACTION READ ONLY')->once()->ordered()->andReturnTrue();
        $connection->shouldReceive('cursor')->with('select broken_function()')->once()->ordered()->andReturn($this->failingRows());
        $connection->shouldReceive('rollBack')->once()->ordered();

        $this->mockDatabaseFacade($connection, 903);
        $this->expectException(RuntimeException::class);

        app(DatabaseQueryExecutor::class)->execute(
            $this->databaseConnection(903, DatabaseDriver::PostgreSql),
            'select broken_function()',
            QueryType::Read,
        );
    }

    public function test_tls_postgresql_passes_normalized_certificates_and_key_to_the_connector(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('beginTransaction')->once()->ordered();
        $connection->shouldReceive('statement')->with('SET TRANSACTION READ ONLY')->once()->ordered()->andReturnTrue();
        $connection->shouldReceive('cursor')->with('select 1 as value')->once()->ordered()->andReturn($this->rows());
        $connection->shouldReceive('rollBack')->once()->ordered();

        $this->mockDatabaseFacade($connection, 904, function (): void {
            $configuration = config('database.connections.crucible_runtime_904');

            $this->assertSame('verify-full', $configuration['sslmode']);
            $this->assertSame('ca certificate', $configuration['sslrootcert']);
            $this->assertSame('client certificate', $configuration['sslcert']);
            $this->assertSame('client private key', $configuration['sslkey']);
            $this->assertPostgreSqlTlsOptionsAreIncludedInLaravelConnectorDsn($configuration);
        });

        app(DatabaseQueryExecutor::class)->execute(
            $this->databaseConnection(904, DatabaseDriver::PostgreSql, DatabaseTlsMode::VerifyIdentity),
            'select 1 as value',
            QueryType::Read,
        );
    }

    public function test_disabled_postgresql_tls_omits_certificates_and_key_from_the_query_connector(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('beginTransaction')->once()->ordered();
        $connection->shouldReceive('statement')->with('SET TRANSACTION READ ONLY')->once()->ordered()->andReturnTrue();
        $connection->shouldReceive('cursor')->with('select 1 as value')->once()->ordered()->andReturn($this->rows());
        $connection->shouldReceive('rollBack')->once()->ordered();

        $this->mockDatabaseFacade($connection, 908, function (): void {
            $configuration = config('database.connections.crucible_runtime_908');

            $this->assertSame('disable', $configuration['sslmode']);
            $this->assertArrayNotHasKey('sslrootcert', $configuration);
            $this->assertArrayNotHasKey('sslcert', $configuration);
            $this->assertArrayNotHasKey('sslkey', $configuration);
        });

        app(DatabaseQueryExecutor::class)->execute(
            $this->databaseConnection(908, DatabaseDriver::PostgreSql, DatabaseTlsMode::Disabled),
            'select 1 as value',
            QueryType::Read,
        );
    }

    public function test_tls_mysql_applies_trusted_ca_certificate_and_key_pdo_options_without_disabling_verification(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('statement')->with('SET TRANSACTION READ ONLY')->once()->ordered()->andReturnTrue();
        $connection->shouldReceive('beginTransaction')->once()->ordered();
        $connection->shouldReceive('cursor')->with('select 1 as value')->once()->ordered()->andReturn($this->rows());
        $connection->shouldReceive('rollBack')->once()->ordered();

        $this->mockDatabaseFacade($connection, 905, function (): void {
            $options = config('database.connections.crucible_runtime_905.options');

            $this->assertSame('ca certificate', $options[Mysql::ATTR_SSL_CA]);
            $this->assertSame('client certificate', $options[Mysql::ATTR_SSL_CERT]);
            $this->assertSame('client private key', $options[Mysql::ATTR_SSL_KEY]);
            $this->assertTrue($options[Mysql::ATTR_SSL_VERIFY_SERVER_CERT]);
        });

        app(DatabaseQueryExecutor::class)->execute(
            $this->databaseConnection(905, DatabaseDriver::MySql, DatabaseTlsMode::VerifyIdentity),
            'select 1 as value',
            QueryType::Read,
        );
    }

    public function test_schema_browser_passes_normalized_postgresql_certificates_and_key_to_the_connector(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')->once()->andReturn([]);

        $this->mockSchemaBrowserDatabaseFacade($connection, 906, function (): void {
            $configuration = config('database.connections.crucible_schema_906');

            $this->assertSame('verify-full', $configuration['sslmode']);
            $this->assertSame('ca certificate', $configuration['sslrootcert']);
            $this->assertSame('client certificate', $configuration['sslcert']);
            $this->assertSame('client private key', $configuration['sslkey']);
            $this->assertPostgreSqlTlsOptionsAreIncludedInLaravelConnectorDsn($configuration);
        });

        app(DatabaseSchemaBrowser::class)->tables(
            $this->databaseConnection(906, DatabaseDriver::PostgreSql, DatabaseTlsMode::VerifyIdentity),
        );
    }

    public function test_disabled_postgresql_tls_omits_certificates_and_key_from_the_schema_connector(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')->once()->andReturn([]);

        $this->mockSchemaBrowserDatabaseFacade($connection, 909, function (): void {
            $configuration = config('database.connections.crucible_schema_909');

            $this->assertSame('disable', $configuration['sslmode']);
            $this->assertArrayNotHasKey('sslrootcert', $configuration);
            $this->assertArrayNotHasKey('sslcert', $configuration);
            $this->assertArrayNotHasKey('sslkey', $configuration);
        });

        app(DatabaseSchemaBrowser::class)->tables(
            $this->databaseConnection(909, DatabaseDriver::PostgreSql, DatabaseTlsMode::Disabled),
        );
    }

    public function test_schema_browser_applies_mysql_tls_pdo_options(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')->once()->andReturn([]);

        $this->mockSchemaBrowserDatabaseFacade($connection, 907, function (): void {
            $options = config('database.connections.crucible_schema_907.options');

            $this->assertSame('ca certificate', $options[Mysql::ATTR_SSL_CA]);
            $this->assertSame('client certificate', $options[Mysql::ATTR_SSL_CERT]);
            $this->assertSame('client private key', $options[Mysql::ATTR_SSL_KEY]);
            $this->assertTrue($options[Mysql::ATTR_SSL_VERIFY_SERVER_CERT]);
        });

        app(DatabaseSchemaBrowser::class)->tables(
            $this->databaseConnection(907, DatabaseDriver::MySql, DatabaseTlsMode::VerifyIdentity),
        );
    }

    private function mockDatabaseFacade(ConnectionInterface $connection, int $connectionId, ?Closure $assertConfiguration = null): void
    {
        $connectionName = 'crucible_runtime_'.$connectionId;

        DB::shouldReceive('purge')->with($connectionName)->twice();
        DB::shouldReceive('connection')->with($connectionName)->once()->andReturnUsing(function () use ($connection, $assertConfiguration): ConnectionInterface {
            $assertConfiguration?->__invoke();

            return $connection;
        });
        DB::shouldReceive('disconnect')->with($connectionName)->once();
    }

    private function mockSchemaBrowserDatabaseFacade(ConnectionInterface $connection, int $connectionId, Closure $assertConfiguration): void
    {
        $connectionName = 'crucible_schema_'.$connectionId;

        DB::shouldReceive('purge')->with($connectionName)->twice();
        DB::shouldReceive('connection')->with($connectionName)->once()->andReturnUsing(function () use ($connection, $assertConfiguration): ConnectionInterface {
            $assertConfiguration();

            return $connection;
        });
        DB::shouldReceive('disconnect')->with($connectionName)->once();
    }

    private function databaseConnection(int $id, DatabaseDriver $driver, DatabaseTlsMode $tlsMode = DatabaseTlsMode::Preferred): DatabaseConnection
    {
        $databaseConnection = new DatabaseConnection;
        $databaseConnection->forceFill([
            'id' => $id,
            'driver' => $driver,
            'host' => 'database.test',
            'port' => $driver->defaultPort(),
            'database' => 'application',
            'username' => 'crucible',
            'password' => 'secret',
            'tls_mode' => $tlsMode,
            'tls_ca_certificate' => $tlsMode === DatabaseTlsMode::VerifyIdentity ? 'ca certificate' : null,
            'tls_client_certificate' => $tlsMode === DatabaseTlsMode::VerifyIdentity ? 'client certificate' : null,
            'tls_client_key' => $tlsMode === DatabaseTlsMode::VerifyIdentity ? 'client private key' : null,
            'is_active' => true,
        ]);

        return $databaseConnection;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function assertPostgreSqlTlsOptionsAreIncludedInLaravelConnectorDsn(array $configuration): void
    {
        $connector = new class extends PostgresConnector
        {
            /**
             * @param  array<string, mixed>  $configuration
             */
            public function dsn(array $configuration): string
            {
                return $this->getDsn($configuration);
            }
        };

        $dsn = $connector->dsn($configuration);

        $this->assertStringContainsString(';sslmode=verify-full', $dsn);
        $this->assertStringContainsString(';sslrootcert=ca certificate', $dsn);
        $this->assertStringContainsString(';sslcert=client certificate', $dsn);
        $this->assertStringContainsString(';sslkey=client private key', $dsn);
    }

    /**
     * @return Generator<int, object{value:int}>
     */
    private function rows(): Generator
    {
        yield (object) ['value' => 1];
    }

    /**
     * @return Generator<int, never>
     */
    private function failingRows(): Generator
    {
        yield from [];

        throw new RuntimeException('Query failed.');
    }
}
