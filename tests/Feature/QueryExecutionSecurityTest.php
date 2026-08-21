<?php

namespace Tests\Feature;

use App\Enums\DatabaseDriver;
use App\Enums\QueryType;
use App\Models\DatabaseConnection;
use App\Services\DatabaseQueryExecutor;
use App\Services\QueryGuard;
use Generator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
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

    private function mockDatabaseFacade(ConnectionInterface $connection, int $connectionId): void
    {
        $connectionName = 'crucible_runtime_'.$connectionId;

        DB::shouldReceive('purge')->with($connectionName)->twice();
        DB::shouldReceive('connection')->with($connectionName)->once()->andReturn($connection);
        DB::shouldReceive('disconnect')->with($connectionName)->once();
    }

    private function databaseConnection(int $id, DatabaseDriver $driver): DatabaseConnection
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
            'ssl_mode' => $driver === DatabaseDriver::PostgreSql ? 'prefer' : null,
            'is_active' => true,
        ]);

        return $databaseConnection;
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
