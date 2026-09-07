<?php

namespace App\Support;

use App\Enums\ApplicationDatabaseMigrationStatus;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

final class ApplicationDatabaseMigrationStore
{
    public const CurrentVersion = 1;

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function create(array $state): array
    {
        $id = (string) Str::ulid();
        $now = now()->toIso8601String();
        $state = array_merge($state, [
            'version' => self::CurrentVersion,
            'id' => $id,
            'created_at' => $now,
            'updated_at' => $now,
            'events' => [[
                'at' => $now,
                'event' => 'planned',
            ]],
        ]);

        $this->withLock(function () use ($id, $state): void {
            $current = $this->currentId();

            if ($current !== null) {
                $existing = $this->readUnlocked($current);
                $status = ApplicationDatabaseMigrationStatus::tryFrom((string) ($existing['status'] ?? ''));

                if (! $status?->isTerminal()) {
                    throw new RuntimeException("Application database migration {$current} is still in progress.");
                }
            }

            $this->writeEncrypted($this->planPath($id), $state);
            $this->writePointer($id);
        });

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(?string $id = null): array
    {
        return $this->withLock(function () use ($id): array {
            $id ??= $this->currentId();

            if ($id === null) {
                throw new RuntimeException('No application database migration plan exists.');
            }

            return $this->readUnlocked($id);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->withLock(function (): array {
            $paths = glob($this->directory().'/*.enc');

            if ($paths === false) {
                throw new RuntimeException('Application database migration plans could not be listed.');
            }

            $plans = array_map(function (string $path): array {
                $id = pathinfo($path, PATHINFO_FILENAME);

                return $this->readUnlocked($this->validatedId($id));
            }, $paths);

            usort($plans, static function (array $left, array $right): int {
                $createdAtComparison = strcmp((string) $right['created_at'], (string) $left['created_at']);

                return $createdAtComparison !== 0
                    ? $createdAtComparison
                    : strcmp((string) $right['id'], (string) $left['id']);
            });

            return $plans;
        });
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function update(string $id, callable $callback): array
    {
        return $this->withLock(function () use ($id, $callback): array {
            $state = $callback($this->readUnlocked($id));
            $state['updated_at'] = now()->toIso8601String();
            $this->writeEncrypted($this->planPath($id), $state);

            return $state;
        });
    }

    public function currentId(): ?string
    {
        $path = $this->pointerPath();

        if (! is_file($path)) {
            return null;
        }

        $id = trim((string) file_get_contents($path));

        return $id === '' ? null : $this->validatedId($id);
    }

    public function clearCurrent(string $id): void
    {
        $this->withLock(function () use ($id): void {
            $id = $this->validatedId($id);
            $current = $this->currentId();

            if ($current === null) {
                return;
            }

            if ($current !== $id) {
                throw new RuntimeException('Another application database migration plan is current.');
            }

            if (! unlink($this->pointerPath()) && is_file($this->pointerPath())) {
                throw new RuntimeException('The current migration plan pointer could not be cleared.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function readUnlocked(string $id): array
    {
        $path = $this->planPath($id);

        if (! is_file($path)) {
            throw new RuntimeException("Application database migration plan {$id} was not found.");
        }

        try {
            $encrypted = file_get_contents($path);

            if ($encrypted === false || trim($encrypted) === '') {
                throw new RuntimeException('The migration plan file is unreadable or empty.');
            }

            $json = $this->encrypter()->decryptString(trim($encrypted));
            $state = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('The migration plan could not be decrypted. Check APP_KEY and file integrity.', previous: $exception);
        }

        if (! is_array($state) || ($state['version'] ?? null) !== self::CurrentVersion || ($state['id'] ?? null) !== $id) {
            throw new RuntimeException('The application database migration plan is invalid or unsupported.');
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeEncrypted(string $path, array $state): void
    {
        try {
            $this->ensureDirectory();
            $temporaryPath = tempnam($this->directory(), '.application-database-migration-');

            if ($temporaryPath === false) {
                throw new RuntimeException('A temporary migration plan file could not be created.');
            }

            try {
                $encrypted = $this->encrypter()->encryptString(json_encode($state, JSON_THROW_ON_ERROR));

                if (file_put_contents($temporaryPath, $encrypted, LOCK_EX) === false || ! chmod($temporaryPath, 0600)) {
                    throw new RuntimeException('The migration plan could not be written securely.');
                }

                if (! rename($temporaryPath, $path)) {
                    throw new RuntimeException('The migration plan could not be saved atomically.');
                }
            } finally {
                if (is_file($temporaryPath)) {
                    unlink($temporaryPath);
                }
            }
        } catch (JsonException $exception) {
            throw new RuntimeException('The migration plan could not be encoded.', previous: $exception);
        }
    }

    private function writePointer(string $id): void
    {
        $this->ensureDirectory();
        $temporaryPath = tempnam($this->directory(), '.current-migration-');

        if ($temporaryPath === false) {
            throw new RuntimeException('The migration pointer could not be created.');
        }

        try {
            if (file_put_contents($temporaryPath, $id, LOCK_EX) === false || ! chmod($temporaryPath, 0600)) {
                throw new RuntimeException('The migration pointer could not be written securely.');
            }

            if (! rename($temporaryPath, $this->pointerPath())) {
                throw new RuntimeException('The migration pointer could not be activated atomically.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $callback
     * @return TValue
     */
    private function withLock(callable $callback): mixed
    {
        $this->ensureDirectory();
        $lock = fopen($this->directory().'/.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('The application database migration lock could not be acquired.');
        }

        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function encrypter(): Encrypter
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(Str::after($key, 'base64:'), true);
            $key = $decoded === false ? '' : $decoded;
        }

        $cipher = (string) config('app.cipher');

        if (! Encrypter::supported($key, $cipher)) {
            throw new RuntimeException('APP_KEY is missing or invalid for migration-plan encryption.');
        }

        return new Encrypter($key, $cipher);
    }

    private function ensureDirectory(): void
    {
        $directory = $this->directory();

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The application database migration directory could not be created.');
        }
    }

    private function directory(): string
    {
        return (string) config('database.control_metadata.migration_directory');
    }

    private function pointerPath(): string
    {
        return $this->directory().'/current';
    }

    private function planPath(string $id): string
    {
        return $this->directory().'/'.$this->validatedId($id).'.enc';
    }

    private function validatedId(string $id): string
    {
        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id)) {
            throw new RuntimeException('The application database migration plan identifier is invalid.');
        }

        return $id;
    }
}
