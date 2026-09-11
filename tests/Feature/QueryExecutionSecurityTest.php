<?php

namespace Tests\Feature;

use App\Enums\DatabaseDriver;
use App\Enums\DatabaseTlsMode;
use App\Enums\QueryType;
use App\Enums\SqlStatementFamily;
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
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_insert_upserts_require_update_permission_without_blocking_postgresql_do_update(): void
    {
        $postgresUpsert = <<<'SQL'
INSERT INTO ksj_service_zones (zone_id, hub_code, geom_geojson)
VALUES ('HB0009-AREA-1', 'HB0009', '{"type":"Polygon"}'::jsonb)
ON CONFLICT (zone_id) DO UPDATE SET
    hub_code = EXCLUDED.hub_code,
    geom_geojson = EXCLUDED.geom_geojson,
    updated_at = NOW()
SQL;
        $mysqlUpsert = <<<'SQL'
INSERT INTO service_zones (zone_id, hub_code)
VALUES ('HB0009-AREA-1', 'HB0009')
ON DUPLICATE KEY UPDATE hub_code = VALUES(hub_code)
SQL;
        $settings = Mockery::mock(ApplicationSettings::class);
        $settings->shouldReceive('allowsSqlStatementFamily')
            ->with(SqlStatementFamily::Insert)
            ->twice()
            ->andReturnTrue();
        $settings->shouldReceive('allowsSqlStatementFamily')
            ->with(SqlStatementFamily::Update)
            ->twice()
            ->andReturnTrue();
        $guard = new QueryGuard($settings);

        $this->assertSame(QueryType::Write, $guard->classify($postgresUpsert));
        $this->assertSame(
            [SqlStatementFamily::Insert, SqlStatementFamily::Update],
            $guard->requiredStatementFamilies($postgresUpsert),
        );
        $this->assertSame(QueryType::Write, $guard->classify($mysqlUpsert));
        $this->assertSame(
            [SqlStatementFamily::Insert, SqlStatementFamily::Update],
            $guard->requiredStatementFamilies($mysqlUpsert),
        );
    }

    public function test_insert_conflict_do_nothing_only_requires_insert_permission(): void
    {
        $settings = Mockery::mock(ApplicationSettings::class);
        $settings->shouldReceive('allowsSqlStatementFamily')
            ->with(SqlStatementFamily::Insert)
            ->once()
            ->andReturnTrue();
        $guard = new QueryGuard($settings);
        $sql = "INSERT INTO service_zones (zone_id) VALUES ('HB0009-AREA-1') ON CONFLICT (zone_id) DO NOTHING";

        $this->assertSame(QueryType::Write, $guard->classify($sql));
        $this->assertSame(
            [SqlStatementFamily::Insert],
            $guard->requiredStatementFamilies($sql),
        );
    }

    public function test_insert_upsert_is_blocked_when_update_statements_are_disabled(): void
    {
        $settings = Mockery::mock(ApplicationSettings::class);
        $settings->shouldReceive('allowsSqlStatementFamily')
            ->with(SqlStatementFamily::Insert)
            ->once()
            ->andReturnTrue();
        $settings->shouldReceive('allowsSqlStatementFamily')
            ->with(SqlStatementFamily::Update)
            ->once()
            ->andReturnFalse();
        $guard = new QueryGuard($settings);

        try {
            $guard->validateExecutable("INSERT INTO service_zones (zone_id) VALUES ('HB0009-AREA-1') ON CONFLICT (zone_id) DO UPDATE SET zone_id = EXCLUDED.zone_id");
            $this->fail('An upsert should require UPDATE permission.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['UPDATE statements are disabled by the workspace administrator.'],
                $exception->errors()['sql'],
            );
        }
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
            'DO $$ BEGIN PERFORM refresh_materialized_views(); END $$',
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

    #[DataProvider('tlsModes')]
    public function test_query_executor_configures_every_postgresql_tls_mode_with_temporary_material_files(DatabaseTlsMode $tlsMode): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('beginTransaction')->once()->ordered();
        $connection->shouldReceive('statement')->with('SET TRANSACTION READ ONLY')->once()->ordered()->andReturnTrue();
        $connection->shouldReceive('cursor')->with('select 1 as value')->once()->ordered()->andReturn($this->rows());
        $connection->shouldReceive('rollBack')->once()->ordered();
        $temporaryPaths = [];
        $connectionId = $this->tlsModeConnectionId($tlsMode, 1000);

        $this->mockDatabaseFacade($connection, $connectionId, function () use ($connectionId, $tlsMode, &$temporaryPaths): void {
            $configuration = config("database.connections.crucible_runtime_{$connectionId}");

            $this->assertSame($tlsMode->postgreSqlSslMode(), $configuration['sslmode']);
            $temporaryPaths = $this->assertPostgreSqlTlsConfiguration($configuration, $tlsMode);
        });

        app(DatabaseQueryExecutor::class)->execute(
            $this->databaseConnection($connectionId, DatabaseDriver::PostgreSql, $tlsMode),
            'select 1 as value',
            QueryType::Read,
        );

        $this->assertTemporaryTlsFilesWereCleaned($temporaryPaths);
    }

    #[DataProvider('tlsModes')]
    public function test_query_executor_configures_every_mysql_tls_mode_with_temporary_material_files(DatabaseTlsMode $tlsMode): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('statement')->with('SET TRANSACTION READ ONLY')->once()->ordered()->andReturnTrue();
        $connection->shouldReceive('beginTransaction')->once()->ordered();
        $connection->shouldReceive('cursor')->with('select 1 as value')->once()->ordered()->andReturn($this->rows());
        $connection->shouldReceive('rollBack')->once()->ordered();
        $temporaryPaths = [];
        $connectionId = $this->tlsModeConnectionId($tlsMode, 1100);

        $this->mockDatabaseFacade($connection, $connectionId, function () use ($connectionId, $tlsMode, &$temporaryPaths): void {
            $configuration = config("database.connections.crucible_runtime_{$connectionId}");

            $temporaryPaths = $this->assertMySqlTlsConfiguration($configuration['options'], $tlsMode);
        });

        app(DatabaseQueryExecutor::class)->execute(
            $this->databaseConnection($connectionId, DatabaseDriver::MySql, $tlsMode),
            'select 1 as value',
            QueryType::Read,
        );

        $this->assertTemporaryTlsFilesWereCleaned($temporaryPaths);
    }

    #[DataProvider('tlsModes')]
    public function test_schema_browser_configures_every_postgresql_tls_mode_with_temporary_material_files(DatabaseTlsMode $tlsMode): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')->once()->andReturn([]);
        $temporaryPaths = [];
        $connectionId = $this->tlsModeConnectionId($tlsMode, 1200);

        $this->mockSchemaBrowserDatabaseFacade($connection, $connectionId, function () use ($connectionId, $tlsMode, &$temporaryPaths): void {
            $configuration = config("database.connections.crucible_schema_{$connectionId}");

            $this->assertSame($tlsMode->postgreSqlSslMode(), $configuration['sslmode']);
            $temporaryPaths = $this->assertPostgreSqlTlsConfiguration($configuration, $tlsMode);
        });

        app(DatabaseSchemaBrowser::class)->tables(
            $this->databaseConnection($connectionId, DatabaseDriver::PostgreSql, $tlsMode),
        );

        $this->assertTemporaryTlsFilesWereCleaned($temporaryPaths);
    }

    #[DataProvider('tlsModes')]
    public function test_schema_browser_configures_every_mysql_tls_mode_with_temporary_material_files(DatabaseTlsMode $tlsMode): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')->once()->andReturn([]);
        $temporaryPaths = [];
        $connectionId = $this->tlsModeConnectionId($tlsMode, 1300);

        $this->mockSchemaBrowserDatabaseFacade($connection, $connectionId, function () use ($connectionId, $tlsMode, &$temporaryPaths): void {
            $configuration = config("database.connections.crucible_schema_{$connectionId}");

            $temporaryPaths = $this->assertMySqlTlsConfiguration($configuration['options'], $tlsMode);
        });

        app(DatabaseSchemaBrowser::class)->tables(
            $this->databaseConnection($connectionId, DatabaseDriver::MySql, $tlsMode),
        );

        $this->assertTemporaryTlsFilesWereCleaned($temporaryPaths);
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
        $hasTlsMaterial = $tlsMode !== DatabaseTlsMode::Disabled;
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
            'tls_ca_certificate' => $hasTlsMaterial ? 'ca certificate' : null,
            'tls_client_certificate' => $hasTlsMaterial ? 'client certificate' : null,
            'tls_client_key' => $hasTlsMaterial ? 'client private key' : null,
            'is_active' => true,
        ]);

        return $databaseConnection;
    }

    /**
     * @return array<string, array{0: DatabaseTlsMode}>
     */
    public static function tlsModes(): array
    {
        return array_reduce(DatabaseTlsMode::cases(), function (array $datasets, DatabaseTlsMode $tlsMode): array {
            $datasets[$tlsMode->value] = [$tlsMode];

            return $datasets;
        }, []);
    }

    private function tlsModeConnectionId(DatabaseTlsMode $tlsMode, int $offset): int
    {
        return $offset + array_search($tlsMode, DatabaseTlsMode::cases(), true);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<int, string>
     */
    private function assertPostgreSqlTlsConfiguration(array $configuration, DatabaseTlsMode $tlsMode): array
    {
        if ($tlsMode === DatabaseTlsMode::Disabled) {
            $this->assertArrayNotHasKey('sslrootcert', $configuration);
            $this->assertArrayNotHasKey('sslcert', $configuration);
            $this->assertArrayNotHasKey('sslkey', $configuration);

            return [];
        }

        $paths = [
            $configuration['sslrootcert'],
            $configuration['sslcert'],
            $configuration['sslkey'],
        ];

        $this->assertSame(['ca certificate', 'client certificate', 'client private key'], array_map('file_get_contents', $paths));

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

        $this->assertStringContainsString(';sslmode='.$tlsMode->postgreSqlSslMode(), $dsn);
        $this->assertStringContainsString(';sslrootcert='.$configuration['sslrootcert'], $dsn);
        $this->assertStringContainsString(';sslcert='.$configuration['sslcert'], $dsn);
        $this->assertStringContainsString(';sslkey='.$configuration['sslkey'], $dsn);

        return $paths;
    }

    /**
     * @param  array<int, bool|string>  $options
     * @return array<int, string>
     */
    private function assertMySqlTlsConfiguration(array $options, DatabaseTlsMode $tlsMode): array
    {
        if ($tlsMode === DatabaseTlsMode::Disabled) {
            $this->assertArrayNotHasKey(Mysql::ATTR_SSL_CA, $options);
            $this->assertArrayNotHasKey(Mysql::ATTR_SSL_CERT, $options);
            $this->assertArrayNotHasKey(Mysql::ATTR_SSL_KEY, $options);
            $this->assertArrayNotHasKey(Mysql::ATTR_SSL_VERIFY_SERVER_CERT, $options);

            return [];
        }

        $paths = [
            $options[Mysql::ATTR_SSL_CA],
            $options[Mysql::ATTR_SSL_CERT],
            $options[Mysql::ATTR_SSL_KEY],
        ];

        $this->assertSame(['ca certificate', 'client certificate', 'client private key'], array_map('file_get_contents', $paths));

        if ($tlsMode->verifiesServerCertificate()) {
            $this->assertTrue($options[Mysql::ATTR_SSL_VERIFY_SERVER_CERT]);
        } else {
            $this->assertArrayNotHasKey(Mysql::ATTR_SSL_VERIFY_SERVER_CERT, $options);
        }

        return $paths;
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function assertTemporaryTlsFilesWereCleaned(array $paths): void
    {
        foreach ($paths as $path) {
            $this->assertFileDoesNotExist($path);
        }
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
