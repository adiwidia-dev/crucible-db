<?php

namespace App\Services;

use App\Enums\DatabaseDriver;
use App\Enums\QueryType;
use App\Models\DatabaseConnection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Pdo\Mysql;

class DatabaseQueryExecutor
{
    private const int ResultRowLimit = 1000;

    private const int SampleRowLimit = 25;

    /**
     * @return array{row_count:int, sample_rows:array<int, array<string, mixed>>, result_truncated:bool}
     */
    public function execute(DatabaseConnection $databaseConnection, string $sql, QueryType $queryType): array
    {
        $connectionName = 'crucible_runtime_'.$databaseConnection->id;

        Config::set("database.connections.{$connectionName}", [
            'driver' => $databaseConnection->driver->value,
            'host' => $databaseConnection->host,
            'port' => $databaseConnection->port,
            'database' => $databaseConnection->database,
            'username' => $databaseConnection->username,
            'password' => $databaseConnection->password,
            'prefix' => '',
            ...$this->driverOptions($databaseConnection),
        ]);

        DB::purge($connectionName);

        try {
            if ($queryType === QueryType::Read) {
                $rowCount = 0;
                $sampleRows = [];
                $resultTruncated = false;

                foreach (DB::connection($connectionName)->cursor($sql) as $row) {
                    if ($rowCount >= self::ResultRowLimit) {
                        $resultTruncated = true;

                        break;
                    }

                    $rowCount++;

                    if (count($sampleRows) < self::SampleRowLimit) {
                        $sampleRows[] = json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
                    }
                }

                return [
                    'row_count' => $rowCount,
                    'sample_rows' => $sampleRows,
                    'result_truncated' => $resultTruncated,
                ];
            }

            $affected = DB::connection($connectionName)->affectingStatement($sql);

            return [
                'row_count' => $affected,
                'sample_rows' => [],
                'result_truncated' => false,
            ];
        } finally {
            DB::disconnect($connectionName);
            DB::purge($connectionName);
        }
    }

    /**
     * @return array<string, bool|string|null>
     */
    private function driverOptions(DatabaseConnection $databaseConnection): array
    {
        return match ($databaseConnection->driver) {
            DatabaseDriver::PostgreSql => [
                'charset' => 'utf8',
                'sslmode' => $databaseConnection->ssl_mode ?? 'prefer',
            ],
            DatabaseDriver::MySql => [
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix_indexes' => true,
                'strict' => true,
                'options' => [
                    Mysql::ATTR_USE_BUFFERED_QUERY => false,
                ],
            ],
        };
    }
}
