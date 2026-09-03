<?php

namespace App\Services;

use App\Enums\ApplicationDatabaseDriver;
use App\Support\ApplicationDatabaseBootstrap;
use Pdo\Mysql;
use RuntimeException;

final class ApplicationDatabaseConfiguration
{
    /** @return array<string, mixed> */
    public function activePayload(): array
    {
        $path = (string) config('database.control_metadata.path');

        if (config('database.control_metadata.mode') === ApplicationDatabaseBootstrap::ManagedMode) {
            $managed = ApplicationDatabaseBootstrap::read(
                $path,
                (string) config('app.key'),
                (string) config('app.cipher'),
            );

            if ($managed !== null
                && ApplicationDatabaseBootstrap::fingerprint($managed) === config('database.control_metadata.fingerprint')) {
                return $managed;
            }
        }

        return $this->payloadFromConnection((array) config('database.connections.control'));
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>
     */
    public function payloadFromConnection(array $connection): array
    {
        $driver = ApplicationDatabaseDriver::tryFrom((string) ($connection['driver'] ?? ''))
            ?? throw new RuntimeException('The active control database driver is unsupported.');

        if ($driver === ApplicationDatabaseDriver::Sqlite) {
            return [
                'version' => ApplicationDatabaseBootstrap::CurrentVersion,
                'driver' => $driver->value,
                'url' => $connection['url'] ?? null,
                'database' => (string) ($connection['database'] ?? database_path('database.sqlite')),
                'foreign_key_constraints' => (bool) ($connection['foreign_key_constraints'] ?? true),
                'busy_timeout' => (int) ($connection['busy_timeout'] ?? 5000),
                'journal_mode' => (string) ($connection['journal_mode'] ?? 'WAL'),
                'synchronous' => (string) ($connection['synchronous'] ?? 'FULL'),
                'transaction_mode' => (string) ($connection['transaction_mode'] ?? 'IMMEDIATE'),
            ];
        }

        $payload = [
            'version' => ApplicationDatabaseBootstrap::CurrentVersion,
            'driver' => $driver->value,
            'url' => $connection['url'] ?? null,
            'host' => (string) ($connection['host'] ?? ''),
            'port' => (int) ($connection['port'] ?? $driver->defaultPort()),
            'database' => (string) ($connection['database'] ?? ''),
            'username' => (string) ($connection['username'] ?? ''),
            'password' => (string) ($connection['password'] ?? ''),
            'charset' => (string) ($connection['charset'] ?? ($driver === ApplicationDatabaseDriver::MySql ? 'utf8mb4' : 'utf8')),
        ];

        if ($driver === ApplicationDatabaseDriver::PostgreSql) {
            $payload['pgsql_sslmode'] = (string) ($connection['sslmode'] ?? 'prefer');
        } else {
            $payload['collation'] = (string) ($connection['collation'] ?? 'utf8mb4_unicode_ci');
            $payload['socket'] = (string) ($connection['unix_socket'] ?? '');
            $payload['mysql_ssl_ca'] = (string) (($connection['options'] ?? [])[Mysql::ATTR_SSL_CA] ?? '');
        }

        foreach (['url', 'socket', 'mysql_ssl_ca'] as $optionalKey) {
            if (($payload[$optionalKey] ?? null) === null || $payload[$optionalKey] === '') {
                unset($payload[$optionalKey]);
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function fingerprint(array $payload): string
    {
        return ApplicationDatabaseBootstrap::fingerprint($payload);
    }

    public function activeFingerprint(): string
    {
        return $this->fingerprint($this->activePayload());
    }
}
