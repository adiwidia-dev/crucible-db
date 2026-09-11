<?php

namespace App\Services;

use App\Enums\ApplicationDatabaseDriver;
use App\Support\ApplicationDatabaseBootstrap;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class ApplicationDatabaseManager
{
    private const CandidateConnection = 'application_database_candidate';

    public function usesManagedConfiguration(): bool
    {
        return config('database.control_metadata.mode') === ApplicationDatabaseBootstrap::ManagedMode;
    }

    public function hasManagedConfiguration(): bool
    {
        if (! $this->usesManagedConfiguration()) {
            return false;
        }

        return ApplicationDatabaseBootstrap::read(
            $this->configurationPath(),
            (string) config('app.key'),
            (string) config('app.cipher'),
        ) !== null;
    }

    public function requiresSelection(): bool
    {
        return $this->usesManagedConfiguration() && ! $this->hasManagedConfiguration();
    }

    public function requiresRestart(): bool
    {
        if (! $this->usesManagedConfiguration()) {
            return false;
        }

        $payload = ApplicationDatabaseBootstrap::read(
            $this->configurationPath(),
            (string) config('app.key'),
            (string) config('app.cipher'),
        );

        if ($payload === null) {
            return false;
        }

        return ApplicationDatabaseBootstrap::fingerprint($payload)
            !== config('database.control_metadata.fingerprint');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function provision(array $validated): void
    {
        if (! $this->usesManagedConfiguration()) {
            throw ValidationException::withMessages([
                'driver' => 'Application database selection is disabled when configuration is managed by the environment.',
            ]);
        }

        $lock = $this->acquireProvisioningLock();

        try {
            if ($this->hasManagedConfiguration()) {
                throw ValidationException::withMessages([
                    'driver' => 'The application database has already been configured.',
                ]);
            }

            $payload = $this->payload($validated);
            $driver = ApplicationDatabaseDriver::from((string) $payload['driver']);

            if ($driver->isNetworkDatabase()) {
                $this->prepareNetworkDatabase($payload);
            }

            ApplicationDatabaseBootstrap::write(
                $payload,
                $this->configurationPath(),
                (string) config('app.key'),
                (string) config('app.cipher'),
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(array $validated): array
    {
        $driver = ApplicationDatabaseDriver::from((string) $validated['driver']);

        if ($driver === ApplicationDatabaseDriver::Sqlite) {
            return [
                'version' => ApplicationDatabaseBootstrap::CurrentVersion,
                'driver' => $driver->value,
                'database' => (string) config('database.connections.control.database'),
                'foreign_key_constraints' => (bool) config('database.connections.control.foreign_key_constraints', true),
                'busy_timeout' => (int) config('database.connections.control.busy_timeout', 5000),
                'journal_mode' => (string) config('database.connections.control.journal_mode', 'WAL'),
                'synchronous' => (string) config('database.connections.control.synchronous', 'FULL'),
                'transaction_mode' => (string) config('database.connections.control.transaction_mode', 'IMMEDIATE'),
            ];
        }

        return array_filter([
            'version' => ApplicationDatabaseBootstrap::CurrentVersion,
            'driver' => $driver->value,
            'host' => (string) $validated['host'],
            'port' => (int) $validated['port'],
            'database' => (string) $validated['database'],
            'username' => (string) $validated['username'],
            'password' => (string) $validated['password'],
            'pgsql_sslmode' => $driver === ApplicationDatabaseDriver::PostgreSql
                ? (string) ($validated['pgsql_sslmode'] ?? 'prefer')
                : null,
            'mysql_ssl_ca' => $driver === ApplicationDatabaseDriver::MySql
                ? trim((string) ($validated['mysql_ssl_ca'] ?? ''))
                : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function prepareNetworkDatabase(array $payload): void
    {
        config([
            'database.connections.'.self::CandidateConnection => ApplicationDatabaseBootstrap::connection($payload, base_path()),
        ]);
        DB::purge(self::CandidateConnection);

        try {
            DB::connection(self::CandidateConnection)->getPdo();

            if (Schema::connection(self::CandidateConnection)->getTableListing() !== []) {
                throw ValidationException::withMessages([
                    'database' => 'Use a dedicated empty database. Existing tables were found and will not be overwritten.',
                ]);
            }

            $exitCode = Artisan::call('migrate', [
                '--database' => self::CandidateConnection,
                '--force' => true,
                '--no-interaction' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException(trim(Artisan::output()) ?: 'The application database migrations failed.');
            }

            $schema = Schema::connection(self::CandidateConnection);

            if (! $schema->hasTable('users') || ! $schema->hasTable('native_proxy_leases')) {
                throw new RuntimeException('The application database migration verification failed.');
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'database' => 'The application database could not be prepared. Check the connection, permissions, TLS settings, and that the database is empty.',
            ]);
        } finally {
            DB::disconnect(self::CandidateConnection);
            DB::purge(self::CandidateConnection);
            config()->offsetUnset('database.connections.'.self::CandidateConnection);
        }
    }

    /**
     * @return resource
     */
    private function acquireProvisioningLock()
    {
        $lockPath = $this->configurationPath().'.lock';
        $directory = dirname($lockPath);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The application database configuration directory could not be created.');
        }

        $lock = fopen($lockPath, 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('The application database setup lock could not be acquired.');
        }

        return $lock;
    }

    private function configurationPath(): string
    {
        return (string) config('database.control_metadata.path');
    }
}
