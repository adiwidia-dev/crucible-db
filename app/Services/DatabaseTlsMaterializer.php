<?php

namespace App\Services;

use App\Enums\DatabaseTlsMode;
use App\Models\DatabaseConnection;
use RuntimeException;

class DatabaseTlsMaterializer
{
    /** @var array<int, string> */
    private array $paths = [];

    /**
     * @return array{ca: string|null, client_certificate: string|null, client_key: string|null}
     */
    public function materialize(DatabaseConnection $databaseConnection): array
    {
        if ($databaseConnection->tls_mode === DatabaseTlsMode::Disabled) {
            return [
                'ca' => null,
                'client_certificate' => null,
                'client_key' => null,
            ];
        }

        try {
            return [
                'ca' => $this->write($databaseConnection->tls_ca_certificate),
                'client_certificate' => $this->write($databaseConnection->tls_client_certificate),
                'client_key' => $this->write($databaseConnection->tls_client_key),
            ];
        } catch (RuntimeException $exception) {
            $this->cleanup();

            throw $exception;
        }
    }

    public function cleanup(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->paths = [];
    }

    private function write(?string $pem): ?string
    {
        if (blank($pem)) {
            return null;
        }

        $directory = storage_path('framework/cache');

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to prepare temporary TLS material storage.');
        }

        $path = tempnam($directory, 'crucible-tls-');

        if ($path === false) {
            throw new RuntimeException('Unable to materialize temporary TLS credentials.');
        }

        if (file_put_contents($path, $pem) === false) {
            unlink($path);

            throw new RuntimeException('Unable to materialize temporary TLS credentials.');
        }

        chmod($path, 0600);
        $this->paths[] = $path;

        return $path;
    }
}
