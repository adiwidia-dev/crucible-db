<?php

namespace App\Services;

use App\Enums\NativeProxyConnectionStatus;
use App\Enums\NativeProxyLeaseStatus;
use App\Models\NativeProxyConnection;
use App\Models\NativeProxyLease;
use App\Models\QuerySession;
use Illuminate\Queue\QueueManager;
use RuntimeException;
use Throwable;

final class ApplicationDatabaseMigrationSafety
{
    /** @var list<string> */
    private const Queues = ['queries', 'default', 'notifications', 'mail'];

    public function __construct(
        private readonly ApplicationDatabaseMigrationFence $fence,
        private readonly QueueManager $queues,
    ) {}

    public function engage(string $planId, int $drainTimeoutSeconds): void
    {
        $this->fence->engage($planId);

        try {
            $this->assertNoActiveAccess();
            $this->waitUntilQueuesAreEmpty($drainTimeoutSeconds);
            $this->pauseQueues();
            $this->assertQueuesAreEmpty();
            $this->assertNoActiveAccess();
        } catch (Throwable $exception) {
            $this->resumeQueues();
            $this->fence->release($planId);

            throw $exception;
        }
    }

    public function release(string $planId): void
    {
        $active = $this->fence->active();

        if ($active !== null && $active['plan_id'] !== $planId) {
            throw new RuntimeException('The maintenance fence belongs to another migration plan.');
        }

        $this->resumeQueues();
        $this->fence->release($planId);
    }

    /** @return array{query_sessions: int, native_leases: int, native_connections: int} */
    public function activity(): array
    {
        return [
            'query_sessions' => QuerySession::query()
                ->whereNull('ended_at')
                ->where('expires_at', '>', now())
                ->count(),
            'native_leases' => NativeProxyLease::query()
                ->whereIn('status', [NativeProxyLeaseStatus::PendingCredentials, NativeProxyLeaseStatus::Active])
                ->where('expires_at', '>', now())
                ->count(),
            'native_connections' => NativeProxyConnection::query()
                ->whereIn('status', [NativeProxyConnectionStatus::Reserved, NativeProxyConnectionStatus::Active])
                ->whereNull('disconnected_at')
                ->count(),
        ];
    }

    /** @return array<string, int> */
    public function queueSizes(): array
    {
        $connectionName = $this->queueConnectionName();
        $connection = $this->queues->connection($connectionName);
        $sizes = [];

        foreach (self::Queues as $queue) {
            $sizes[$queue] = $connection->size($queue);
        }

        return $sizes;
    }

    private function assertNoActiveAccess(): void
    {
        $activity = $this->activity();

        if (array_sum($activity) > 0) {
            throw new RuntimeException(sprintf(
                'Cannot migrate while access is active (%d query sessions, %d native leases, %d native connections). End or revoke them first.',
                $activity['query_sessions'],
                $activity['native_leases'],
                $activity['native_connections'],
            ));
        }
    }

    private function waitUntilQueuesAreEmpty(int $timeout): void
    {
        $deadline = microtime(true) + max(0, $timeout);

        do {
            if (array_sum($this->queueSizes()) === 0) {
                return;
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Queued or running work did not drain before the migration timeout.');
    }

    private function assertQueuesAreEmpty(): void
    {
        if (array_sum($this->queueSizes()) > 0) {
            throw new RuntimeException('New queued work appeared while the application database migration was being fenced.');
        }
    }

    private function pauseQueues(): void
    {
        $connection = $this->queueConnectionName();

        foreach (self::Queues as $queue) {
            $this->queues->pause($connection, $queue);
        }
    }

    private function resumeQueues(): void
    {
        $connection = $this->queueConnectionName();

        foreach (self::Queues as $queue) {
            $this->queues->resume($connection, $queue);
        }
    }

    private function queueConnectionName(): string
    {
        $configured = (string) config('queue.default');

        return $configured === 'sync' ? 'sync' : $configured;
    }
}
