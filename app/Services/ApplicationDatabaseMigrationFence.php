<?php

namespace App\Services;

use RuntimeException;

final class ApplicationDatabaseMigrationFence
{
    /** @return array{plan_id: string, activated_at: string}|null */
    public function active(): ?array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return null;
        }

        $state = json_decode((string) file_get_contents($path), true);

        if (! is_array($state) || ! is_string($state['plan_id'] ?? null) || ! is_string($state['activated_at'] ?? null)) {
            throw new RuntimeException('The application database migration fence is corrupt.');
        }

        return ['plan_id' => $state['plan_id'], 'activated_at' => $state['activated_at']];
    }

    public function engage(string $planId): void
    {
        $active = $this->active();

        if ($active !== null && $active['plan_id'] !== $planId) {
            throw new RuntimeException("Application database migration {$active['plan_id']} already holds the maintenance fence.");
        }

        if ($active !== null) {
            return;
        }

        $directory = dirname($this->path());

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The application database migration fence directory could not be created.');
        }

        $temporaryPath = tempnam($directory, '.application-database-fence-');

        if ($temporaryPath === false) {
            throw new RuntimeException('The application database migration fence could not be created.');
        }

        try {
            $payload = json_encode(['plan_id' => $planId, 'activated_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR);

            if (file_put_contents($temporaryPath, $payload, LOCK_EX) === false || ! chmod($temporaryPath, 0600)) {
                throw new RuntimeException('The application database migration fence could not be written securely.');
            }

            if (! rename($temporaryPath, $this->path())) {
                throw new RuntimeException('The application database migration fence could not be activated atomically.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function release(string $planId): void
    {
        $active = $this->active();

        if ($active === null) {
            return;
        }

        if ($active['plan_id'] !== $planId) {
            throw new RuntimeException('The maintenance fence belongs to another migration plan.');
        }

        if (! unlink($this->path()) && is_file($this->path())) {
            throw new RuntimeException('The application database migration fence could not be released.');
        }
    }

    public function isActive(): bool
    {
        return $this->active() !== null;
    }

    private function path(): string
    {
        return (string) config('database.control_metadata.migration_fence_path');
    }
}
