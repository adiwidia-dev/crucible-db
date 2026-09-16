<?php

namespace App\Services\NativeProxy;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class ProxyHealth
{
    private const string CacheKey = 'native-proxy:health:latest';

    /**
     * @return array{status: 'disabled'|'healthy'|'unhealthy'|'version_mismatch', checked_at: string|null, proxy_id: string|null, version: string|null, message: string|null}
     */
    public function latest(): array
    {
        if (! config('native_proxy.enabled')) {
            return $this->disabledSnapshot();
        }

        /** @var array{status: 'disabled'|'healthy'|'unhealthy'|'version_mismatch', checked_at: string|null, proxy_id: string|null, version: string|null, message: string|null}|null $snapshot */
        $snapshot = Cache::get(self::CacheKey);

        return $snapshot ?? $this->unavailableSnapshot('The native proxy health check has not reported yet.');
    }

    /**
     * @return array{status: 'disabled'|'healthy'|'unhealthy'|'version_mismatch', checked_at: string|null, proxy_id: string|null, version: string|null, message: string|null}
     */
    public function refresh(): array
    {
        $url = config('native_proxy.health_url');

        if (! config('native_proxy.enabled')) {
            return $this->disabledSnapshot();
        }

        if (! is_string($url) || $url === '') {
            return $this->store($this->unavailableSnapshot('The native proxy readiness endpoint is not configured.'));
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(1)
                ->timeout(2)
                ->get($url);

            if (! $response->successful()) {
                return $this->store([
                    'status' => 'unhealthy',
                    'checked_at' => now()->toIso8601String(),
                    'proxy_id' => null,
                    'version' => null,
                    'message' => "Proxy readiness returned HTTP {$response->status()}.",
                ]);
            }

            $versionValue = $response->json('version');
            $version = is_string($versionValue) && $versionValue !== '' ? $versionValue : null;
            $expectedVersion = config('native_proxy.expected_version');
            $versionMismatch = is_string($expectedVersion) && $expectedVersion !== '' && $version !== $expectedVersion;

            return $this->store([
                'status' => $versionMismatch ? 'version_mismatch' : 'healthy',
                'checked_at' => now()->toIso8601String(),
                'proxy_id' => is_string($response->json('proxy_id')) ? $response->json('proxy_id') : null,
                'version' => $version,
                'message' => $versionMismatch
                    ? "Expected native proxy version {$expectedVersion}; received {$version}."
                    : null,
            ]);
        } catch (Throwable) {
            return $this->store([
                'status' => 'unhealthy',
                'checked_at' => now()->toIso8601String(),
                'proxy_id' => null,
                'version' => null,
                'message' => 'The native proxy readiness endpoint could not be reached.',
            ]);
        }
    }

    /**
     * @param  array{status: 'disabled'|'healthy'|'unhealthy'|'version_mismatch', checked_at: string|null, proxy_id: string|null, version: string|null, message: string|null}  $snapshot
     * @return array{status: 'disabled'|'healthy'|'unhealthy'|'version_mismatch', checked_at: string|null, proxy_id: string|null, version: string|null, message: string|null}
     */
    private function store(array $snapshot): array
    {
        Cache::put(self::CacheKey, $snapshot, now()->addSeconds((int) config('native_proxy.health_cache_seconds', 30)));

        return $snapshot;
    }

    /**
     * @return array{status: 'disabled', checked_at: null, proxy_id: null, version: null, message: string}
     */
    private function disabledSnapshot(): array
    {
        return [
            'status' => 'disabled',
            'checked_at' => null,
            'proxy_id' => null,
            'version' => null,
            'message' => 'Native client access is not enabled for this workspace.',
        ];
    }

    /**
     * @return array{status: 'unhealthy', checked_at: string|null, proxy_id: null, version: null, message: string}
     */
    private function unavailableSnapshot(string $message): array
    {
        return [
            'status' => 'unhealthy',
            'checked_at' => null,
            'proxy_id' => null,
            'version' => null,
            'message' => $message,
        ];
    }
}
