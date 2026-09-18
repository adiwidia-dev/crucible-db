<?php

namespace App\Services;

use App\Services\NativeProxy\ProxyHealth;
use Composer\InstalledVersions;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Throwable;

class SystemStatus
{
    private const string SnapshotCacheKey = 'system-status:latest';

    private const string SchedulerHeartbeatCacheKey = 'system-status:scheduler-heartbeat';

    public function __construct(
        private readonly ProxyHealth $proxyHealth,
        private readonly RedisFactory $redis,
        private readonly MasterSupervisorRepository $masterSupervisors,
        private readonly SupervisorRepository $supervisors,
    ) {}

    /**
     * @return array{checked_at: string|null, components: array<string, array{status: 'degraded'|'disabled'|'healthy'|'unknown'|'unhealthy', detail: string, metadata: array<string, string|int|null>}>}
     */
    public function latest(): array
    {
        /** @var array{checked_at: string|null, components: array<string, array{status: 'degraded'|'disabled'|'healthy'|'unknown'|'unhealthy', detail: string, metadata: array<string, string|int|null>}>}|null $snapshot */
        $snapshot = Cache::get(self::SnapshotCacheKey);

        return $snapshot ?? [
            'checked_at' => null,
            'components' => [
                'database' => $this->unknown('The system status check has not reported yet.'),
                'redis' => $this->unknown('The system status check has not reported yet.'),
                'horizon' => $this->unknown('The system status check has not reported yet.'),
                'scheduler' => $this->unknown('The scheduler heartbeat has not reported yet.'),
                'native_proxy' => $this->nativeProxyStatus(),
            ],
        ];
    }

    /**
     * @return array{checked_at: string, components: array<string, array{status: 'degraded'|'disabled'|'healthy'|'unknown'|'unhealthy', detail: string, metadata: array<string, string|int|null>}>}
     */
    public function refresh(): array
    {
        $snapshot = [
            'checked_at' => now()->toIso8601String(),
            'components' => [
                'database' => $this->databaseStatus(),
                'redis' => $this->redisStatus(),
                'horizon' => $this->horizonStatus(),
                'scheduler' => $this->schedulerStatus(),
                'native_proxy' => $this->nativeProxyStatus(),
            ],
        ];

        Cache::put(self::SnapshotCacheKey, $snapshot, now()->addMinutes(3));

        return $snapshot;
    }

    public function recordSchedulerHeartbeat(): void
    {
        Cache::put(self::SchedulerHeartbeatCacheKey, now()->toIso8601String(), now()->addMinutes(3));
    }

    /**
     * @return array{status: 'healthy', detail: string, metadata: array{driver: string, php_version: string, version: string, wayfinder_version: string}}
     */
    public function applicationRuntime(): array
    {
        return [
            'status' => 'healthy',
            'detail' => 'This page was served successfully by the configured application runtime.',
            'metadata' => [
                'driver' => (string) config('octane.server'),
                'php_version' => PHP_VERSION,
                'version' => $this->applicationVersion(),
                'wayfinder_version' => $this->installedPackageVersion('laravel/wayfinder'),
            ],
        ];
    }

    /**
     * @return array{status: 'healthy'|'unhealthy', detail: string, metadata: array{version: string}}
     */
    public function wayfinderStatus(): array
    {
        $generatedRouteFile = resource_path('js/routes/system-status/index.ts');

        return [
            'status' => is_file($generatedRouteFile) ? 'healthy' : 'unhealthy',
            'detail' => is_file($generatedRouteFile)
                ? 'Generated route contracts are present in this application build.'
                : 'The generated route contract for system status is missing from this application build.',
            'metadata' => [
                'version' => $this->installedPackageVersion('laravel/wayfinder'),
            ],
        ];
    }

    /**
     * @return array{status: 'healthy'|'unhealthy', detail: string, metadata: array<string, string|int|null>}
     */
    private function databaseStatus(): array
    {
        try {
            DB::connection('control')->select('select 1');

            return $this->healthy('The control database accepted a bounded connectivity check.');
        } catch (Throwable) {
            return $this->unhealthy('The control database could not be reached by the status check.');
        }
    }

    /**
     * @return array{status: 'healthy'|'unhealthy', detail: string, metadata: array<string, string|int|null>}
     */
    private function redisStatus(): array
    {
        try {
            $this->redis->connection()->ping();

            return $this->healthy('Redis accepted a bounded ping.');
        } catch (Throwable) {
            return $this->unhealthy('Redis could not be reached by the status check.');
        }
    }

    /**
     * @return array{status: 'degraded'|'healthy'|'unhealthy', detail: string, metadata: array{masters: int, supervisors: int}}
     */
    private function horizonStatus(): array
    {
        try {
            $masters = $this->masterSupervisors->all();
            $supervisors = $this->supervisors->all();

            if ($masters === []) {
                return [
                    'status' => 'unhealthy',
                    'detail' => 'No active Horizon master supervisor was reported.',
                    'metadata' => ['masters' => 0, 'supervisors' => 0],
                ];
            }

            $isPaused = collect([...$masters, ...$supervisors])
                ->contains(fn (object $process): bool => (get_object_vars($process)['status'] ?? null) === 'paused');

            return [
                'status' => $isPaused ? 'degraded' : 'healthy',
                'detail' => $isPaused
                    ? 'Horizon is reporting one or more paused supervisors.'
                    : 'Horizon is reporting active supervisors.',
                'metadata' => [
                    'masters' => count($masters),
                    'supervisors' => count($supervisors),
                ],
            ];
        } catch (Throwable) {
            return [
                'status' => 'unhealthy',
                'detail' => 'Horizon state could not be read from Redis.',
                'metadata' => ['masters' => 0, 'supervisors' => 0],
            ];
        }
    }

    /**
     * @return array{status: 'healthy'|'unknown'|'unhealthy', detail: string, metadata: array{last_heartbeat_at: string|null}}
     */
    private function schedulerStatus(): array
    {
        $heartbeat = Cache::get(self::SchedulerHeartbeatCacheKey);

        if (! is_string($heartbeat) || $heartbeat === '') {
            return [
                'status' => 'unhealthy',
                'detail' => 'No scheduler heartbeat was observed in the expected interval.',
                'metadata' => ['last_heartbeat_at' => null],
            ];
        }

        return [
            'status' => 'healthy',
            'detail' => 'The scheduler recorded a recent heartbeat.',
            'metadata' => ['last_heartbeat_at' => $heartbeat],
        ];
    }

    /**
     * @return array{status: 'degraded'|'disabled'|'healthy'|'unhealthy', detail: string, metadata: array{proxy_id: string|null, version: string|null}}
     */
    private function nativeProxyStatus(): array
    {
        $snapshot = $this->proxyHealth->latest();

        return [
            'status' => match ($snapshot['status']) {
                'healthy' => 'healthy',
                'disabled' => 'disabled',
                'version_mismatch' => 'degraded',
                default => 'unhealthy',
            },
            'detail' => $snapshot['message'] ?? 'The native proxy reported ready for client connections.',
            'metadata' => [
                'proxy_id' => $snapshot['proxy_id'],
                'version' => $snapshot['version'],
            ],
        ];
    }

    /**
     * @return array{status: 'healthy', detail: string, metadata: array<string, string|int|null>}
     */
    private function healthy(string $detail): array
    {
        return ['status' => 'healthy', 'detail' => $detail, 'metadata' => []];
    }

    /**
     * @return array{status: 'unknown', detail: string, metadata: array<string, string|int|null>}
     */
    private function unknown(string $detail): array
    {
        return ['status' => 'unknown', 'detail' => $detail, 'metadata' => []];
    }

    /**
     * @return array{status: 'unhealthy', detail: string, metadata: array<string, string|int|null>}
     */
    private function unhealthy(string $detail): array
    {
        return ['status' => 'unhealthy', 'detail' => $detail, 'metadata' => []];
    }

    private function applicationVersion(): string
    {
        $packagePath = base_path('package.json');

        if (! is_file($packagePath)) {
            return 'Unknown';
        }

        $package = json_decode((string) file_get_contents($packagePath), true);

        return is_array($package) && is_string($package['version'] ?? null)
            ? 'v'.$package['version']
            : 'Unknown';
    }

    private function installedPackageVersion(string $package): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return 'Unknown';
        }

        return InstalledVersions::getPrettyVersion($package) ?? 'Unknown';
    }
}
