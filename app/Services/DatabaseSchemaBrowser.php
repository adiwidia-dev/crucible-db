<?php

namespace App\Services;

use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class DatabaseSchemaBrowser
{
    /**
     * @return array<int, array{name:string, columns:array<int, array{name:string, type:string|null, nullable:bool|null}>}>
     */
    public function tables(DatabaseConnection $databaseConnection): array
    {
        $connectionName = 'crucible_schema_'.$databaseConnection->id;

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
            $rows = match ($databaseConnection->driver) {
                DatabaseDriver::PostgreSql => DB::connection($connectionName)->select(
                    "select tables.table_name, columns.column_name, columns.data_type, columns.is_nullable
                    from information_schema.tables
                    left join information_schema.columns
                        on columns.table_schema = tables.table_schema
                        and columns.table_name = tables.table_name
                    where tables.table_schema not in ('pg_catalog', 'information_schema')
                        and tables.table_type = 'BASE TABLE'
                    order by tables.table_name, columns.ordinal_position"
                ),
                DatabaseDriver::MySql => DB::connection($connectionName)->select(
                    'select tables.table_name, columns.column_name, columns.data_type, columns.is_nullable
                    from information_schema.tables
                    left join information_schema.columns
                        on columns.table_schema = tables.table_schema
                        and columns.table_name = tables.table_name
                    where tables.table_schema = database()
                        and tables.table_type = ?
                    order by tables.table_name, columns.ordinal_position',
                    ['BASE TABLE'],
                ),
            };

            $tables = [];

            foreach ($rows as $row) {
                /** @var array<string, mixed> $attributes */
                $attributes = get_object_vars($row);
                $tableName = (string) ($attributes['table_name'] ?? $attributes['TABLE_NAME'] ?? '');

                $tables[$tableName] ??= [
                    'name' => $tableName,
                    'columns' => [],
                ];

                $columnName = $attributes['column_name'] ?? $attributes['COLUMN_NAME'] ?? null;

                if ($columnName !== null) {
                    $nullable = $attributes['is_nullable'] ?? $attributes['IS_NULLABLE'] ?? null;

                    $tables[$tableName]['columns'][] = [
                        'name' => (string) $columnName,
                        'type' => isset($attributes['data_type']) || isset($attributes['DATA_TYPE'])
                            ? (string) ($attributes['data_type'] ?? $attributes['DATA_TYPE'])
                            : null,
                        'nullable' => is_string($nullable) ? strtoupper($nullable) === 'YES' : null,
                    ];
                }
            }

            return array_values($tables);
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
            ],
        };
    }
}
