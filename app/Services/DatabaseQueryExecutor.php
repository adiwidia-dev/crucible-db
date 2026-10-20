<?php

namespace App\Services;

use App\Enums\DatabaseDriver;
use App\Enums\DatabaseTlsMode;
use App\Enums\QueryType;
use App\Models\DatabaseConnection;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Pdo\Mysql;

class DatabaseQueryExecutor
{
    private const int ResultRowLimit = 5000;

    private const int ResultByteLimit = 26_214_400;

    private const int SampleRowLimit = 25;

    public function __construct(private DatabaseTlsMaterializer $tlsMaterializer) {}

    /**
     * @return array{row_count:int, sample_rows:array<int, array<string, mixed>>, result_rows:array<int, array<string, mixed>>, result_byte_count:int, result_truncated?:bool}
     */
    public function execute(DatabaseConnection $databaseConnection, string $sql, QueryType $queryType): array
    {
        return $this->executeTracked($databaseConnection, $sql, $queryType);
    }

    /**
     * @param  (Closure(string, ?string): void)|null  $onConnected
     * @return array{row_count:int, sample_rows:array<int, array<string, mixed>>, result_rows:array<int, array<string, mixed>>, result_byte_count:int, result_truncated?:bool}
     */
    public function executeTracked(
        DatabaseConnection $databaseConnection,
        string $sql,
        QueryType $queryType,
        ?int $timeoutSeconds = null,
        ?Closure $onConnected = null,
        ?string $executionTag = null,
    ): array {
        return $this->withRuntimeConnection(
            $databaseConnection,
            function (ConnectionInterface $connection) use ($databaseConnection, $sql, $queryType, $timeoutSeconds, $onConnected, $executionTag): array {
                $applicationName = $executionTag === null
                    ? null
                    : mb_substr('crucible-browser:'.$executionTag, 0, 63);
                if ($onConnected !== null || $executionTag !== null || $timeoutSeconds !== null) {
                    $backendProcessId = $this->prepareConnection(
                        $connection,
                        $databaseConnection->driver,
                        $queryType,
                        $timeoutSeconds,
                        $applicationName,
                    );

                    if ($onConnected !== null) {
                        $onConnected($backendProcessId, $applicationName);
                    }
                }

                $executableSql = $executionTag === null
                    ? $sql
                    : '/* crucible-query:'.$executionTag." */\n".$sql;

                if ($queryType === QueryType::Read) {
                    return $this->executeReadOnly(
                        $connection,
                        $databaseConnection->driver,
                        $executableSql,
                        $timeoutSeconds,
                    );
                }

                if ($databaseConnection->driver === DatabaseDriver::PostgreSql && $timeoutSeconds !== null) {
                    $connection->statement('SET statement_timeout TO '.($timeoutSeconds * 1000));
                }

                $affected = $connection->affectingStatement($executableSql);

                return [
                    'row_count' => $affected,
                    'sample_rows' => [],
                    'result_rows' => [],
                    'result_byte_count' => 0,
                    'result_truncated' => false,
                ];
            },
            $executionTag,
        );
    }

    public function withRuntimeConnection(
        DatabaseConnection $databaseConnection,
        Closure $callback,
        ?string $connectionSuffix = null,
    ): mixed {
        $connectionName = 'crucible_runtime_'.$databaseConnection->id;

        if ($connectionSuffix !== null) {
            $connectionName .= '_'.substr(hash('sha256', $connectionSuffix), 0, 12);
        }
        $tlsMaterial = $this->tlsMaterializer->materialize($databaseConnection);

        try {
            Config::set("database.connections.{$connectionName}", [
                'driver' => $databaseConnection->driver->value,
                'host' => $databaseConnection->host,
                'port' => $databaseConnection->port,
                'database' => $databaseConnection->database,
                'username' => $databaseConnection->username,
                'password' => $databaseConnection->password,
                'prefix' => '',
                ...$this->driverOptions($databaseConnection, $tlsMaterial),
            ]);

            DB::purge($connectionName);

            return $callback(DB::connection($connectionName));
        } finally {
            DB::disconnect($connectionName);
            DB::purge($connectionName);
            $this->tlsMaterializer->cleanup();
        }
    }

    /**
     * @return array{row_count:int, sample_rows:array<int, array<string, mixed>>, result_rows:array<int, array<string, mixed>>, result_byte_count:int, result_truncated:bool}
     */
    private function executeReadOnly(
        ConnectionInterface $connection,
        DatabaseDriver $driver,
        string $sql,
        ?int $timeoutSeconds,
    ): array {
        $transactionStarted = false;
        $rows = null;

        try {
            if ($driver === DatabaseDriver::MySql) {
                $connection->statement('SET TRANSACTION READ ONLY');
            }

            $connection->beginTransaction();
            $transactionStarted = true;

            if ($driver === DatabaseDriver::PostgreSql) {
                $connection->statement('SET TRANSACTION READ ONLY');

                if ($timeoutSeconds !== null) {
                    $connection->statement('SET LOCAL statement_timeout TO '.($timeoutSeconds * 1000));
                }
            }

            $rowCount = 0;
            $sampleRows = [];
            $resultRows = [];
            $resultByteCount = 0;
            $resultTruncated = false;
            $rows = $connection->cursor($sql);

            foreach ($rows as $row) {
                if ($rowCount >= self::ResultRowLimit) {
                    $resultTruncated = true;

                    break;
                }

                $resultRow = json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
                $resultRowByteCount = strlen(json_encode($resultRow, JSON_THROW_ON_ERROR));

                if ($resultByteCount + $resultRowByteCount > self::ResultByteLimit) {
                    $resultTruncated = true;

                    break;
                }

                $rowCount++;
                $resultRows[] = $resultRow;
                $resultByteCount += $resultRowByteCount;

                if (count($sampleRows) < self::SampleRowLimit) {
                    $sampleRows[] = $resultRow;
                }
            }

            return [
                'row_count' => $rowCount,
                'sample_rows' => $sampleRows,
                'result_rows' => $resultRows,
                'result_byte_count' => $resultByteCount,
                'result_truncated' => $resultTruncated,
            ];
        } finally {
            unset($rows);

            if ($transactionStarted) {
                $connection->rollBack();
            }
        }
    }

    private function prepareConnection(
        ConnectionInterface $connection,
        DatabaseDriver $driver,
        QueryType $queryType,
        ?int $timeoutSeconds,
        ?string $applicationName,
    ): string {
        if ($driver === DatabaseDriver::PostgreSql) {
            if ($applicationName !== null) {
                $connection->selectOne("select set_config('application_name', ?, false)", [$applicationName]);
            }

            $row = $connection->selectOne('select pg_backend_pid() as process_id');

            return (string) $row->process_id;
        }

        if ($queryType === QueryType::Read && $timeoutSeconds !== null) {
            $connection->statement('SET SESSION max_execution_time = '.($timeoutSeconds * 1000));
        }

        $row = $connection->selectOne('select connection_id() as process_id');

        return (string) $row->process_id;
    }

    /**
     * @param  array{ca: string|null, client_certificate: string|null, client_key: string|null}  $tlsMaterial
     * @return array<string, array<int, bool|string>|bool|string>
     */
    private function driverOptions(DatabaseConnection $databaseConnection, array $tlsMaterial): array
    {
        return match ($databaseConnection->driver) {
            DatabaseDriver::PostgreSql => [
                'charset' => 'utf8',
                ...$this->postgreSqlTlsOptions($databaseConnection, $tlsMaterial),
            ],
            DatabaseDriver::MySql => [
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix_indexes' => true,
                'strict' => true,
                'options' => $this->mySqlPdoOptions($databaseConnection, $tlsMaterial),
            ],
        };
    }

    /**
     * @param  array{ca: string|null, client_certificate: string|null, client_key: string|null}  $tlsMaterial
     * @return array<string, string>
     */
    private function postgreSqlTlsOptions(DatabaseConnection $databaseConnection, array $tlsMaterial): array
    {
        $tlsIsDisabled = $databaseConnection->tls_mode === DatabaseTlsMode::Disabled;

        return array_filter([
            'sslmode' => $databaseConnection->tls_mode->postgreSqlSslMode(),
            'sslrootcert' => $tlsIsDisabled ? null : $tlsMaterial['ca'],
            'sslcert' => $tlsIsDisabled ? null : $tlsMaterial['client_certificate'],
            'sslkey' => $tlsIsDisabled ? null : $tlsMaterial['client_key'],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array{ca: string|null, client_certificate: string|null, client_key: string|null}  $tlsMaterial
     * @return array<int, bool|string>
     */
    private function mySqlPdoOptions(DatabaseConnection $databaseConnection, array $tlsMaterial): array
    {
        $tlsIsDisabled = $databaseConnection->tls_mode === DatabaseTlsMode::Disabled;

        $options = [
            Mysql::ATTR_USE_BUFFERED_QUERY => false,
            Mysql::ATTR_SSL_CA => $tlsIsDisabled ? null : $tlsMaterial['ca'],
            Mysql::ATTR_SSL_CERT => $tlsIsDisabled ? null : $tlsMaterial['client_certificate'],
            Mysql::ATTR_SSL_KEY => $tlsIsDisabled ? null : $tlsMaterial['client_key'],
            Mysql::ATTR_SSL_VERIFY_SERVER_CERT => $databaseConnection->tls_mode->verifiesServerCertificate()
                ? true
                : null,
        ];

        return array_filter($options, static fn (mixed $value): bool => $value !== null);
    }
}
