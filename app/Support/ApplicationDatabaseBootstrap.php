<?php

namespace App\Support;

use App\Enums\ApplicationDatabaseDriver;
use Illuminate\Encryption\Encrypter;
use JsonException;
use Pdo\Mysql;
use RuntimeException;
use Throwable;

final class ApplicationDatabaseBootstrap
{
    public const ManagedMode = 'managed';

    public const EnvironmentMode = 'environment';

    public const CurrentVersion = 1;

    /**
     * @param  array<string, mixed>  $fallbackConnection
     * @return array{connection: array<string, mixed>, mode: string, path: string, configured: bool, fingerprint: string|null}
     */
    public static function resolve(
        string $basePath,
        string $mode,
        string $path,
        array $fallbackConnection,
        string $applicationKey,
        string $cipher,
    ): array {
        if (! in_array($mode, [self::ManagedMode, self::EnvironmentMode], true)) {
            throw new RuntimeException('CRUCIBLE_DATABASE_CONFIG_MODE must be managed or environment.');
        }

        if ($mode === self::EnvironmentMode) {
            return [
                'connection' => $fallbackConnection,
                'mode' => $mode,
                'path' => $path,
                'configured' => true,
                'fingerprint' => null,
            ];
        }

        $payload = self::read($path, $applicationKey, $cipher);

        return [
            'connection' => $payload === null
                ? $fallbackConnection
                : self::connection($payload, $basePath),
            'mode' => $mode,
            'path' => $path,
            'configured' => $payload !== null,
            'fingerprint' => $payload === null ? null : self::fingerprint($payload),
        ];
    }

    public static function configurationPath(string $basePath, ?string $configuredPath = null): string
    {
        $configuredPath ??= $basePath.'/storage/app/crucible/application-database.enc';

        if ($configuredPath === '') {
            throw new RuntimeException('CRUCIBLE_DATABASE_CONFIG_FILE cannot be empty.');
        }

        return str_starts_with($configuredPath, DIRECTORY_SEPARATOR)
            ? $configuredPath
            : $basePath.DIRECTORY_SEPARATOR.$configuredPath;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function connection(array $payload, string $basePath): array
    {
        $driver = self::validatedDriver($payload);

        if ($driver === ApplicationDatabaseDriver::Sqlite) {
            $database = (string) ($payload['database'] ?? $basePath.'/database/database.sqlite');

            if ($database !== ':memory:' && ! str_starts_with($database, DIRECTORY_SEPARATOR)) {
                $database = $basePath.DIRECTORY_SEPARATOR.$database;
            }

            return [
                'driver' => 'sqlite',
                'url' => $payload['url'] ?? null,
                'database' => $database,
                'prefix' => '',
                'foreign_key_constraints' => (bool) ($payload['foreign_key_constraints'] ?? true),
                'busy_timeout' => (int) ($payload['busy_timeout'] ?? 5000),
                'journal_mode' => (string) ($payload['journal_mode'] ?? 'WAL'),
                'synchronous' => (string) ($payload['synchronous'] ?? 'FULL'),
                'transaction_mode' => (string) ($payload['transaction_mode'] ?? 'IMMEDIATE'),
            ];
        }

        self::requireNetworkFields($payload);

        if ($driver === ApplicationDatabaseDriver::MySql) {
            $sslCa = trim((string) ($payload['mysql_ssl_ca'] ?? ''));

            return [
                'driver' => 'mysql',
                'url' => $payload['url'] ?? null,
                'host' => (string) $payload['host'],
                'port' => (string) $payload['port'],
                'database' => (string) $payload['database'],
                'username' => (string) $payload['username'],
                'password' => (string) $payload['password'],
                'unix_socket' => (string) ($payload['socket'] ?? ''),
                'charset' => (string) ($payload['charset'] ?? 'utf8mb4'),
                'collation' => (string) ($payload['collation'] ?? 'utf8mb4_unicode_ci'),
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
                'options' => extension_loaded('pdo_mysql') && $sslCa !== ''
                    ? [Mysql::ATTR_SSL_CA => $sslCa]
                    : [],
            ];
        }

        return [
            'driver' => 'pgsql',
            'url' => $payload['url'] ?? null,
            'host' => (string) $payload['host'],
            'port' => (string) $payload['port'],
            'database' => (string) $payload['database'],
            'username' => (string) $payload['username'],
            'password' => (string) $payload['password'],
            'charset' => (string) ($payload['charset'] ?? 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => (string) ($payload['pgsql_sslmode'] ?? 'prefer'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function read(string $path, string $applicationKey, string $cipher): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $encrypted = file_get_contents($path);

        if ($encrypted === false || trim($encrypted) === '') {
            throw new RuntimeException('The application database configuration file is unreadable or empty.');
        }

        try {
            $json = self::encrypter($applicationKey, $cipher)->decryptString(trim($encrypted));
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'The application database configuration could not be decrypted. Check APP_KEY and the configuration file integrity.',
                previous: $exception,
            );
        }

        if (! is_array($payload) || ($payload['version'] ?? null) !== self::CurrentVersion) {
            throw new RuntimeException('The application database configuration version is unsupported.');
        }

        self::validatedDriver($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function write(array $payload, string $path, string $applicationKey, string $cipher): void
    {
        $payload['version'] = self::CurrentVersion;
        self::validatedDriver($payload);

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The application database configuration directory could not be created.');
        }

        $temporaryPath = tempnam($directory, '.application-database-');

        if ($temporaryPath === false) {
            throw new RuntimeException('A temporary application database configuration file could not be created.');
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $encrypted = self::encrypter($applicationKey, $cipher)->encryptString($json);

            if (file_put_contents($temporaryPath, $encrypted, LOCK_EX) === false) {
                throw new RuntimeException('The application database configuration could not be written.');
            }

            if (! chmod($temporaryPath, 0600)) {
                throw new RuntimeException('The application database configuration permissions could not be secured.');
            }

            if (! rename($temporaryPath, $path)) {
                throw new RuntimeException('The application database configuration could not be activated atomically.');
            }
        } catch (JsonException $exception) {
            throw new RuntimeException('The application database configuration could not be encoded.', previous: $exception);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fingerprint(array $payload): string
    {
        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new RuntimeException('The application database configuration could not be fingerprinted.', previous: $exception);
        }
    }

    private static function encrypter(string $applicationKey, string $cipher): Encrypter
    {
        if (str_starts_with($applicationKey, 'base64:')) {
            $decodedKey = base64_decode(substr($applicationKey, 7), true);
            $applicationKey = $decodedKey === false ? '' : $decodedKey;
        }

        if (! Encrypter::supported($applicationKey, $cipher)) {
            throw new RuntimeException('APP_KEY is missing or is not valid for the configured cipher.');
        }

        return new Encrypter($applicationKey, $cipher);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function validatedDriver(array $payload): ApplicationDatabaseDriver
    {
        return ApplicationDatabaseDriver::tryFrom((string) ($payload['driver'] ?? ''))
            ?? throw new RuntimeException('The application database driver must be sqlite, mysql, or pgsql.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function requireNetworkFields(array $payload): void
    {
        foreach (['host', 'port', 'database', 'username', 'password'] as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new RuntimeException("The application database configuration is missing {$field}.");
            }
        }
    }
}
