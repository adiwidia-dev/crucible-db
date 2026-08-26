<?php

namespace App\Services;

use App\Enums\DatabaseDriver;
use App\Enums\DatabaseTlsMode;
use App\Models\DatabaseConnection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Pdo\Mysql;

class DatabaseSchemaBrowser
{
    public function __construct(private DatabaseTlsMaterializer $tlsMaterializer) {}

    /**
     * @return array<int, array{name:string, columns:array<int, array{name:string, type:string|null, nullable:bool|null}>}>
     */
    public function tables(DatabaseConnection $databaseConnection): array
    {
        $connectionName = 'crucible_schema_'.$databaseConnection->id;
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
            $this->tlsMaterializer->cleanup();
        }
    }

    /**
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
     * @return array<int, bool|string>
     */
    private function mySqlPdoOptions(DatabaseConnection $databaseConnection, array $tlsMaterial): array
    {
        $tlsIsDisabled = $databaseConnection->tls_mode === DatabaseTlsMode::Disabled;

        $options = [
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
