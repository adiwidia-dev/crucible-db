<?php

namespace App\Services;

use App\Enums\ApplicationDatabaseDriver;
use App\Support\ApplicationDatabaseBootstrap;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;
use Throwable;

final class ApplicationDatabaseCopyEngine
{
    public const SourceConnection = 'application_database_migration_source';

    public const DestinationConnection = 'application_database_migration_destination';

    /** @var list<string> */
    private const ExcludedTables = ['cache', 'cache_locks', 'jobs', 'migrations', 'sessions'];

    /**
     * @param  array<string, mixed>  $sourcePayload
     * @param  array<string, mixed>  $destinationPayload
     * @param  array<string, array<string, mixed>>  $completedTables
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array<string, array<string, mixed>>
     */
    public function copy(
        array $sourcePayload,
        array $destinationPayload,
        array $completedTables,
        callable $progress,
        bool $isResume = false,
        bool $resetDestination = false,
    ): array {
        $this->configureConnections($sourcePayload, $destinationPayload);

        try {
            $this->prepareDestination($destinationPayload, $isResume);
            $tables = $this->orderedTables(self::SourceConnection);
            $this->assertSchemasMatch($tables);

            if ($resetDestination) {
                $this->resetDestinationTables($tables);
                $completedTables = [];
            }

            foreach ($tables as $table) {
                if (($completedTables[$table]['status'] ?? null) === 'verified') {
                    continue;
                }

                $result = $this->copyTable($table, ! $resetDestination);
                $completedTables[$table] = $result;
                $progress($table, $result);
            }

            return $completedTables;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * @param  array<string, mixed>  $sourcePayload
     * @param  array<string, mixed>  $destinationPayload
     * @param  array<string, array<string, mixed>>  $expectedTables
     * @return array<string, array<string, mixed>>
     */
    public function verify(array $sourcePayload, array $destinationPayload, array $expectedTables): array
    {
        $this->configureConnections($sourcePayload, $destinationPayload);

        try {
            $tables = $this->orderedTables(self::SourceConnection);
            $this->assertSchemasMatch($tables);
            $verified = [];

            foreach ($tables as $table) {
                $canonicalTypes = $this->columnTypes($table, self::DestinationConnection);
                $result = $this->hashTable($table, self::SourceConnection, $canonicalTypes);
                $destination = $this->hashTable($table, self::DestinationConnection, $canonicalTypes);

                if ($result['rows'] !== $destination['rows'] || $result['hash'] !== $destination['hash']) {
                    throw new RuntimeException("Verification failed for table {$table}.");
                }

                if (isset($expectedTables[$table])
                    && (($expectedTables[$table]['rows'] ?? null) !== $result['rows']
                        || ($expectedTables[$table]['hash'] ?? null) !== $result['hash'])) {
                    throw new RuntimeException("The fenced source changed after table {$table} was copied.");
                }

                $verified[$table] = [
                    'status' => 'verified',
                    'rows' => $result['rows'],
                    'hash' => $result['hash'],
                    'verified_at' => now()->toIso8601String(),
                ];
            }

            return $verified;
        } finally {
            $this->disconnect();
        }
    }

    /** @return list<string> */
    public function orderedTables(string $connection): array
    {
        $schema = Schema::connection($connection);
        $tables = collect($schema->getTableListing())
            ->map(fn (string $table): string => str_contains($table, '.') ? (string) str($table)->afterLast('.') : $table)
            ->reject(fn (string $table): bool => in_array($table, self::ExcludedTables, true))
            ->sort()
            ->values()
            ->all();
        $tableSet = array_fill_keys($tables, true);
        $dependencies = [];

        foreach ($tables as $table) {
            $dependencies[$table] = collect($schema->getForeignKeys($table))
                ->pluck('foreign_table')
                ->filter(fn (mixed $foreignTable): bool => is_string($foreignTable)
                    && $foreignTable !== $table
                    && isset($tableSet[$foreignTable]))
                ->unique()
                ->sort()
                ->values()
                ->all();
        }

        $ordered = [];

        while ($dependencies !== []) {
            $ready = collect($dependencies)
                ->filter(fn (array $requires): bool => $requires === [])
                ->keys()
                ->sort()
                ->values()
                ->all();

            if ($ready === []) {
                throw new RuntimeException('The application database schema contains a cross-table foreign-key cycle that cannot be copied safely.');
            }

            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($dependencies[$table]);
            }

            foreach ($dependencies as $table => $requires) {
                $dependencies[$table] = array_values(array_diff($requires, $ready));
            }
        }

        return $ordered;
    }

    /**
     * @param  array<string, mixed>  $destinationPayload
     */
    private function prepareDestination(array $destinationPayload, bool $isResume): void
    {
        if (($destinationPayload['driver'] ?? null) === ApplicationDatabaseDriver::Sqlite->value) {
            $database = (string) ApplicationDatabaseBootstrap::connection($destinationPayload, base_path())['database'];

            if ($database !== ':memory:' && ! is_file($database)) {
                $directory = dirname($database);

                if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                    throw new RuntimeException('The destination SQLite directory could not be created.');
                }

                if (! touch($database)) {
                    throw new RuntimeException('The destination SQLite database could not be created.');
                }
            }
        }

        DB::connection(self::DestinationConnection)->getPdo();
        $schema = Schema::connection(self::DestinationConnection);
        $tables = $schema->getTableListing();

        if (! $isResume && $tables !== []) {
            throw new RuntimeException('The destination database must be empty before the first copy attempt.');
        }

        $exitCode = Artisan::call('migrate', [
            '--database' => self::DestinationConnection,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException(trim(Artisan::output()) ?: 'Destination migrations failed.');
        }
    }

    /** @return array<string, mixed> */
    private function copyTable(string $table, bool $clearTable): array
    {
        $destination = DB::connection(self::DestinationConnection);

        if ($clearTable) {
            $destination->table($table)->delete();
        }
        $order = $this->orderColumns($table, self::SourceConnection);
        $destinationColumns = $this->columnTypes($table, self::DestinationConnection);
        $sourceQuery = DB::connection(self::SourceConnection)->table($table);

        foreach ($order as $column) {
            $sourceQuery->orderBy($column);
        }

        $sourceQuery->chunk(250, function ($rows) use ($destination, $destinationColumns, $table): void {
            $records = $rows->map(fn (object $row): array => $this->normalizeRow((array) $row, $destinationColumns))->all();

            if ($records !== []) {
                $destination->table($table)->insert($records);
            }
        });

        $this->synchronizeSequence($table, $destination, Schema::connection(self::DestinationConnection));
        $source = $this->hashTable($table, self::SourceConnection, $destinationColumns);
        $copied = $this->hashTable($table, self::DestinationConnection, $destinationColumns);

        if ($source !== $copied) {
            throw new RuntimeException("Copy verification failed for table {$table}.");
        }

        return [
            'status' => 'verified',
            'rows' => $source['rows'],
            'hash' => $source['hash'],
            'verified_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, string>|null  $types
     * @return array{rows: int, hash: string}
     */
    private function hashTable(string $table, string $connection, ?array $types = null): array
    {
        $types ??= $this->columnTypes($table, $connection);
        $query = DB::connection($connection)->table($table);

        foreach ($this->orderColumns($table, $connection) as $column) {
            $query->orderBy($column);
        }

        $context = hash_init('sha256');
        $rows = 0;

        $query->chunk(250, function ($records) use (&$rows, $context, $types): void {
            foreach ($records as $record) {
                $normalized = $this->canonicalRow((array) $record, $types);
                ksort($normalized);
                hash_update($context, json_encode($normalized, JSON_THROW_ON_ERROR)."\n");
                $rows++;
            }
        });

        return ['rows' => $rows, 'hash' => hash_final($context)];
    }

    /** @param list<string> $sourceTables */
    private function assertSchemasMatch(array $sourceTables): void
    {
        $destinationTables = collect(Schema::connection(self::DestinationConnection)->getTableListing())
            ->map(fn (string $table): string => str_contains($table, '.') ? (string) str($table)->afterLast('.') : $table)
            ->reject(fn (string $table): bool => in_array($table, self::ExcludedTables, true))
            ->sort()
            ->values()
            ->all();

        $expected = $sourceTables;
        sort($expected);

        if ($expected !== $destinationTables) {
            throw new RuntimeException('Source and destination application schemas do not contain the same durable tables.');
        }

        foreach ($sourceTables as $table) {
            $sourceColumns = Schema::connection(self::SourceConnection)->getColumnListing($table);
            $destinationColumns = Schema::connection(self::DestinationConnection)->getColumnListing($table);
            sort($sourceColumns);
            sort($destinationColumns);

            if ($sourceColumns !== $destinationColumns) {
                throw new RuntimeException("Source and destination columns differ for table {$table}.");
            }
        }
    }

    /** @return list<string> */
    private function orderColumns(string $table, string $connection): array
    {
        $indexes = Schema::connection($connection)->getIndexes($table);
        $index = collect($indexes)->first(fn (array $index): bool => $index['primary'])
            ?? collect($indexes)->first(fn (array $index): bool => $index['unique']);

        if (is_array($index) && $index['columns'] !== []) {
            return $index['columns'];
        }

        $columns = Schema::connection($connection)->getColumnListing($table);

        if ($columns === []) {
            throw new RuntimeException("Table {$table} has no columns.");
        }

        return [$columns[0]];
    }

    /** @return array<string, string> */
    private function columnTypes(string $table, string $connection): array
    {
        return collect(Schema::connection($connection)->getColumns($table))
            ->mapWithKeys(fn (array $column): array => [$column['name'] => strtolower($column['type_name'])])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $types
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row, array $types): array
    {
        foreach ($row as $column => $value) {
            if ($value === null) {
                continue;
            }

            $type = $types[$column] ?? '';

            if (in_array($type, ['bool', 'boolean'], true)) {
                $row[$column] = (bool) $value;
            } elseif (in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'serial', 'bigserial'], true)) {
                $row[$column] = (int) $value;
            } elseif (in_array($type, ['json', 'jsonb'], true) && is_string($value)) {
                $row[$column] = $this->canonicalJson($value);
            } elseif ($this->isTextualType($type) && is_scalar($value)) {
                $row[$column] = (string) $value;
            } elseif (is_resource($value)) {
                $contents = stream_get_contents($value);
                $row[$column] = $contents === false ? '' : $contents;
            }
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $types
     * @return array<string, mixed>
     */
    private function canonicalRow(array $row, array $types): array
    {
        $row = $this->normalizeRow($row, $types);

        foreach ($row as $column => $value) {
            if ($value === null) {
                continue;
            }

            $type = $types[$column] ?? '';

            if (str_contains($type, 'timestamp') || in_array($type, ['datetime', 'date'], true)) {
                $row[$column] = CarbonImmutable::parse((string) $value)->utc()->format('Y-m-d H:i:s.u');
            } elseif (in_array($type, ['decimal', 'numeric', 'real', 'float', 'double'], true)) {
                $numeric = (string) $value;
                $row[$column] = str_contains($numeric, '.')
                    ? rtrim(rtrim($numeric, '0'), '.')
                    : $numeric;
            }
        }

        return $row;
    }

    private function isTextualType(string $type): bool
    {
        return in_array($type, ['enum', 'set', 'uuid'], true)
            || str_contains($type, 'char')
            || str_ends_with($type, 'text');
    }

    private function canonicalJson(string $value): string
    {
        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            $decoded = $this->sortJson($decoded);

            return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            return $value;
        }
    }

    private function sortJson(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortJson($item);
        }

        if (Arr::isAssoc($value)) {
            ksort($value);
        }

        return $value;
    }

    private function synchronizeSequence(string $table, Connection $connection, Builder $schema): void
    {
        if ($connection->getDriverName() !== ApplicationDatabaseDriver::PostgreSql->value) {
            return;
        }

        foreach ($schema->getColumns($table) as $column) {
            if (! $column['auto_increment']) {
                continue;
            }

            $columnName = $column['name'];
            $escapedTable = str_replace("'", "''", $table);
            $escapedColumn = str_replace("'", "''", $columnName);
            $sequence = $connection->selectOne("select pg_get_serial_sequence('{$escapedTable}', '{$escapedColumn}') as sequence_name");
            $sequenceName = is_object($sequence) ? ($sequence->sequence_name ?? null) : null;

            if (! is_string($sequenceName) || $sequenceName === '') {
                continue;
            }

            $maximum = $connection->table($table)->max($columnName);
            $connection->select('select setval(?::regclass, ?, ?)', [
                $sequenceName,
                $maximum === null ? 1 : (int) $maximum,
                $maximum !== null,
            ]);
        }
    }

    /** @param list<string> $tables */
    private function resetDestinationTables(array $tables): void
    {
        $schema = Schema::connection(self::DestinationConnection);
        $connection = DB::connection(self::DestinationConnection);

        foreach (array_reverse($tables) as $table) {
            $connection->table($table)->delete();
        }

        foreach (['cache', 'cache_locks', 'jobs', 'sessions'] as $ephemeralTable) {
            if ($schema->hasTable($ephemeralTable)) {
                $connection->table($ephemeralTable)->delete();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $sourcePayload
     * @param  array<string, mixed>  $destinationPayload
     */
    private function configureConnections(array $sourcePayload, array $destinationPayload): void
    {
        config([
            'database.connections.'.self::SourceConnection => ApplicationDatabaseBootstrap::connection($sourcePayload, base_path()),
            'database.connections.'.self::DestinationConnection => ApplicationDatabaseBootstrap::connection($destinationPayload, base_path()),
        ]);
        DB::purge(self::SourceConnection);
        DB::purge(self::DestinationConnection);
        DB::connection(self::SourceConnection)->getPdo();
    }

    private function disconnect(): void
    {
        foreach ([self::SourceConnection, self::DestinationConnection] as $connection) {
            try {
                DB::disconnect($connection);
            } catch (Throwable) {
            }

            DB::purge($connection);
            config()->offsetUnset('database.connections.'.$connection);
        }
    }
}
