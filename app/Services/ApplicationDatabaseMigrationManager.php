<?php

namespace App\Services;

use App\Enums\ApplicationDatabaseDriver;
use App\Enums\ApplicationDatabaseMigrationStatus;
use App\Models\User;
use App\Support\ApplicationDatabaseBootstrap;
use App\Support\ApplicationDatabaseMigrationStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class ApplicationDatabaseMigrationManager
{
    private const CandidateConnection = 'application_database_plan_candidate';

    public function __construct(
        private readonly ApplicationDatabaseConfiguration $configuration,
        private readonly ApplicationDatabaseCopyEngine $copyEngine,
        private readonly ApplicationDatabaseMigrationSafety $safety,
        private readonly ApplicationDatabaseMigrationFence $fence,
        private readonly ApplicationDatabaseMigrationStore $store,
    ) {}

    /**
     * @param  array<string, mixed>  $destinationPayload
     * @return array<string, mixed>
     */
    public function plan(array $destinationPayload): array
    {
        return $this->fence->runExclusive(null, function () use ($destinationPayload): array {
            $this->assertManagedConfiguration();
            $sourcePayload = $this->configuration->activePayload();
            $sourceFingerprint = $this->configuration->fingerprint($sourcePayload);
            $destinationFingerprint = $this->configuration->fingerprint($destinationPayload);

            if (hash_equals($sourceFingerprint, $destinationFingerprint)) {
                throw new RuntimeException('Source and destination application databases are the same.');
            }

            $this->assertDestinationIsEmpty($destinationPayload);
            $tables = $this->inspectSource($sourcePayload);

            return $this->store->create([
                'status' => ApplicationDatabaseMigrationStatus::Planned->value,
                'source' => [
                    'driver' => $sourcePayload['driver'],
                    'database' => $sourcePayload['database'] ?? null,
                    'fingerprint' => $sourceFingerprint,
                    'payload' => $sourcePayload,
                ],
                'destination' => [
                    'driver' => $destinationPayload['driver'],
                    'database' => $destinationPayload['database'] ?? null,
                    'fingerprint' => $destinationFingerprint,
                    'payload' => $destinationPayload,
                ],
                'excluded_tables' => ['cache', 'cache_locks', 'jobs', 'migrations', 'sessions'],
                'planned_tables' => $tables,
                'tables' => [],
            ]);
        });
    }

    /** @return array<string, mixed> */
    public function inspect(?string $id = null): array
    {
        $state = $this->store->read($id);
        $state['maintenance_fence'] = $this->fence->active();
        $state['active_fingerprint'] = $this->configuration->activeFingerprint();
        $status = ApplicationDatabaseMigrationStatus::from((string) $state['status']);
        $expectedRestartFingerprint = match ($status) {
            ApplicationDatabaseMigrationStatus::ActivationPendingRestart => $state['destination']['fingerprint'],
            ApplicationDatabaseMigrationStatus::RollbackPendingRestart => $state['source']['fingerprint'],
            default => null,
        };
        $state['restart_ready'] = is_string($expectedRestartFingerprint)
            ? hash_equals($expectedRestartFingerprint, $state['active_fingerprint'])
            : null;
        $state['activity'] = $this->safety->activity();
        $state['queue_sizes'] = $this->safety->queueSizes();

        unset($state['source']['payload'], $state['destination']['payload']);

        return $state;
    }

    /** @return array<string, mixed> */
    public function recordOperatorEvent(string $id, string $event, User $actor): array
    {
        return $this->fence->runExclusive($id, function () use ($actor, $event, $id): array {
            return $this->store->update($id, function (array $state) use ($actor, $event): array {
                $state['events'][] = [
                    'at' => now()->toIso8601String(),
                    'event' => $event,
                    'actor' => [
                        'id' => $actor->getKey(),
                        'name' => $actor->name,
                    ],
                ];

                return $state;
            });
        });
    }

    /** @return array<string, mixed> */
    public function migrate(?string $id, int $drainTimeoutSeconds): array
    {
        return $this->fence->runExclusive(
            $id,
            fn (): array => $this->migrateWithoutLock($id, $drainTimeoutSeconds),
        );
    }

    /** @return array<string, mixed> */
    public function cancel(?string $id): array
    {
        return $this->fence->runExclusive($id, function () use ($id): array {
            $this->assertManagedConfiguration();
            $state = $this->store->read($id);
            $id = (string) $state['id'];
            $status = ApplicationDatabaseMigrationStatus::from((string) $state['status']);

            if (! in_array($status, [
                ApplicationDatabaseMigrationStatus::Planned,
                ApplicationDatabaseMigrationStatus::Failed,
            ], true)) {
                throw new RuntimeException("Migration {$id} cannot be cancelled from status {$status->value}.");
            }

            if ($this->configuration->activeFingerprint() !== $state['source']['fingerprint']) {
                throw new RuntimeException('The active application database no longer matches this migration source.');
            }

            $this->safety->release($id);
            $state = $this->transition($id, ApplicationDatabaseMigrationStatus::Cancelled, 'cancelled');
            $this->store->clearCurrent($id);

            return $state;
        });
    }

    /** @return array<string, mixed> */
    private function migrateWithoutLock(?string $id, int $drainTimeoutSeconds): array
    {
        $state = $this->store->read($id);
        $id = (string) $state['id'];
        $status = ApplicationDatabaseMigrationStatus::from((string) $state['status']);

        if (! in_array($status, [
            ApplicationDatabaseMigrationStatus::Planned,
            ApplicationDatabaseMigrationStatus::Copying,
            ApplicationDatabaseMigrationStatus::Copied,
            ApplicationDatabaseMigrationStatus::Failed,
        ], true)) {
            throw new RuntimeException("Migration {$id} cannot be copied from status {$status->value}.");
        }

        if ($this->configuration->activeFingerprint() !== $state['source']['fingerprint']) {
            throw new RuntimeException('The active application database no longer matches this migration source.');
        }

        $this->safety->engage($id, $drainTimeoutSeconds);
        $isInterruptedResume = $status === ApplicationDatabaseMigrationStatus::Copying;
        $restartCopy = in_array($status, [
            ApplicationDatabaseMigrationStatus::Copied,
            ApplicationDatabaseMigrationStatus::Failed,
        ], true);

        try {
            $this->transition(
                $id,
                ApplicationDatabaseMigrationStatus::Copying,
                $isInterruptedResume ? 'copy_resumed' : 'copy_started',
            );
            $tables = $this->copyEngine->copy(
                $state['source']['payload'],
                $state['destination']['payload'],
                $restartCopy ? [] : ($state['tables'] ?? []),
                function (string $table, array $result) use ($id): void {
                    $this->store->update($id, function (array $state) use ($table, $result): array {
                        $state['tables'][$table] = $result;

                        return $state;
                    });
                },
                $status !== ApplicationDatabaseMigrationStatus::Planned,
                $restartCopy,
            );

            return $this->store->update($id, function (array $state) use ($tables): array {
                $state['tables'] = $tables;
                $state['status'] = ApplicationDatabaseMigrationStatus::Copied->value;
                $state['events'][] = ['at' => now()->toIso8601String(), 'event' => 'copy_completed'];

                return $state;
            });
        } catch (Throwable $exception) {
            try {
                $this->store->update($id, function (array $state) use ($exception): array {
                    $state['status'] = ApplicationDatabaseMigrationStatus::Failed->value;
                    $state['events'][] = [
                        'at' => now()->toIso8601String(),
                        'event' => 'copy_failed',
                        'message' => $exception->getMessage(),
                    ];

                    return $state;
                });
            } finally {
                $this->safety->release($id);
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function verify(?string $id): array
    {
        return $this->fence->runExclusive(
            $id,
            fn (): array => $this->verifyWithoutLock($id),
        );
    }

    /** @return array<string, mixed> */
    private function verifyWithoutLock(?string $id): array
    {
        $state = $this->store->read($id);
        $id = (string) $state['id'];
        $status = ApplicationDatabaseMigrationStatus::from((string) $state['status']);

        if (! in_array($status, [ApplicationDatabaseMigrationStatus::Copied, ApplicationDatabaseMigrationStatus::Verified], true)) {
            throw new RuntimeException("Migration {$id} cannot be verified from status {$status->value}.");
        }

        $this->assertFenceOwnedBy($id);
        try {
            $tables = $this->copyEngine->verify(
                $state['source']['payload'],
                $state['destination']['payload'],
                $state['tables'],
            );
        } catch (Throwable $exception) {
            $this->store->update($id, function (array $state) use ($exception): array {
                $state['events'][] = [
                    'at' => now()->toIso8601String(),
                    'event' => 'verification_failed',
                    'message' => $exception->getMessage(),
                ];

                return $state;
            });

            throw $exception;
        }

        return $this->store->update($id, function (array $state) use ($tables): array {
            $state['tables'] = $tables;
            $state['status'] = ApplicationDatabaseMigrationStatus::Verified->value;
            $state['events'][] = ['at' => now()->toIso8601String(), 'event' => 'verification_completed'];

            return $state;
        });
    }

    /** @return array<string, mixed> */
    public function activate(?string $id, bool $finalize): array
    {
        return $this->fence->runExclusive(
            $id,
            fn (): array => $this->activateWithoutLock($id, $finalize),
        );
    }

    /** @return array<string, mixed> */
    private function activateWithoutLock(?string $id, bool $finalize): array
    {
        $this->assertManagedConfiguration();
        $state = $this->store->read($id);
        $id = (string) $state['id'];
        $status = ApplicationDatabaseMigrationStatus::from((string) $state['status']);

        if ($finalize && $status === ApplicationDatabaseMigrationStatus::Active) {
            $this->safety->release($id);

            return $state;
        }

        $this->assertFenceOwnedBy($id);

        if (! $finalize) {
            if (! in_array($status, [
                ApplicationDatabaseMigrationStatus::Verified,
                ApplicationDatabaseMigrationStatus::ActivationPendingRestart,
            ], true)) {
                throw new RuntimeException('Only a verified migration can be activated.');
            }

            $activeFingerprint = $this->configuration->activeFingerprint();

            if ($status === ApplicationDatabaseMigrationStatus::ActivationPendingRestart
                && $activeFingerprint === $state['destination']['fingerprint']) {
                return $state;
            }

            if ($activeFingerprint !== $state['source']['fingerprint']) {
                throw new RuntimeException('The active application database no longer matches the migration source.');
            }

            if ($status === ApplicationDatabaseMigrationStatus::Verified) {
                $state = $this->transition($id, ApplicationDatabaseMigrationStatus::ActivationPendingRestart, 'activation_requested');
            }

            $this->writeActiveConfiguration($state['destination']['payload']);

            return $state;
        }

        if ($status !== ApplicationDatabaseMigrationStatus::ActivationPendingRestart) {
            throw new RuntimeException('Activation is not waiting for restart finalization.');
        }

        if ($this->configuration->activeFingerprint() !== $state['destination']['fingerprint']) {
            throw new RuntimeException('The destination is not active yet. Restart every app, Horizon worker, and scheduler process before finalizing.');
        }

        $state = $this->transition($id, ApplicationDatabaseMigrationStatus::Active, 'activation_finalized');
        $this->safety->release($id);

        return $state;
    }

    /** @return array<string, mixed> */
    public function rollback(?string $id, bool $finalize, int $drainTimeoutSeconds = 30): array
    {
        return $this->fence->runExclusive(
            $id,
            fn (): array => $this->rollbackWithoutLock($id, $finalize, $drainTimeoutSeconds),
        );
    }

    /** @return array<string, mixed> */
    private function rollbackWithoutLock(?string $id, bool $finalize, int $drainTimeoutSeconds): array
    {
        $this->assertManagedConfiguration();
        $state = $this->store->read($id);
        $id = (string) $state['id'];
        $status = ApplicationDatabaseMigrationStatus::from((string) $state['status']);

        if (! $finalize) {
            if (! in_array($status, [
                ApplicationDatabaseMigrationStatus::Active,
                ApplicationDatabaseMigrationStatus::RollbackPendingRestart,
            ], true)) {
                throw new RuntimeException('Only an active migration can be rolled back.');
            }

            if ($status === ApplicationDatabaseMigrationStatus::RollbackPendingRestart) {
                $this->assertFenceOwnedBy($id);
                $activeFingerprint = $this->configuration->activeFingerprint();

                if ($activeFingerprint === $state['source']['fingerprint']) {
                    return $state;
                }

                if ($activeFingerprint !== $state['destination']['fingerprint']) {
                    throw new RuntimeException('The active application database no longer matches the rollback destination or source.');
                }

                $this->writeActiveConfiguration($state['source']['payload']);

                return $state;
            }

            if ($this->configuration->activeFingerprint() !== $state['destination']['fingerprint']) {
                throw new RuntimeException('The active application database no longer matches the migration destination.');
            }

            $this->safety->engage($id, $drainTimeoutSeconds);

            try {
                $rollbackTables = $this->copyEngine->copy(
                    $state['destination']['payload'],
                    $state['source']['payload'],
                    [],
                    function (string $table, array $result) use ($id): void {
                        $this->store->update($id, function (array $state) use ($table, $result): array {
                            $state['rollback_tables'][$table] = $result;

                            return $state;
                        });
                    },
                    true,
                    true,
                );
                $this->copyEngine->verify(
                    $state['destination']['payload'],
                    $state['source']['payload'],
                    $rollbackTables,
                );
                $state = $this->store->update($id, function (array $state) use ($rollbackTables): array {
                    $state['rollback_tables'] = $rollbackTables;
                    $state['status'] = ApplicationDatabaseMigrationStatus::RollbackPendingRestart->value;
                    $state['events'][] = ['at' => now()->toIso8601String(), 'event' => 'rollback_copy_verified'];
                    $state['events'][] = ['at' => now()->toIso8601String(), 'event' => 'rollback_requested'];

                    return $state;
                });
            } catch (Throwable $exception) {
                try {
                    $this->store->update($id, function (array $state) use ($exception): array {
                        $state['events'][] = [
                            'at' => now()->toIso8601String(),
                            'event' => 'rollback_copy_failed',
                            'message' => $exception->getMessage(),
                        ];

                        return $state;
                    });
                } finally {
                    $this->safety->release($id);
                }

                throw $exception;
            }

            $this->writeActiveConfiguration($state['source']['payload']);

            return $state;
        }

        if ($status === ApplicationDatabaseMigrationStatus::RolledBack) {
            $this->safety->release($id);

            return $state;
        }

        if ($status !== ApplicationDatabaseMigrationStatus::RollbackPendingRestart) {
            throw new RuntimeException('Rollback is not waiting for restart finalization.');
        }

        if ($this->configuration->activeFingerprint() !== $state['source']['fingerprint']) {
            throw new RuntimeException('The source is not active yet. Restart every app, Horizon worker, and scheduler process before finalizing.');
        }

        $state = $this->transition($id, ApplicationDatabaseMigrationStatus::RolledBack, 'rollback_finalized');
        $this->safety->release($id);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array{rows: int}>
     */
    private function inspectSource(array $payload): array
    {
        config(['database.connections.'.self::CandidateConnection => ApplicationDatabaseBootstrap::connection($payload, base_path())]);
        DB::purge(self::CandidateConnection);

        try {
            DB::connection(self::CandidateConnection)->getPdo();
            $tables = [];

            foreach ($this->copyEngine->orderedTables(self::CandidateConnection) as $table) {
                $tables[$table] = ['rows' => DB::connection(self::CandidateConnection)->table($table)->count()];
            }

            return $tables;
        } finally {
            DB::purge(self::CandidateConnection);
            config()->offsetUnset('database.connections.'.self::CandidateConnection);
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertDestinationIsEmpty(array $payload): void
    {
        $driver = ApplicationDatabaseDriver::from((string) $payload['driver']);

        if ($driver === ApplicationDatabaseDriver::Sqlite) {
            $path = (string) ApplicationDatabaseBootstrap::connection($payload, base_path())['database'];

            if ($path === ':memory:') {
                throw new RuntimeException('An in-memory SQLite database cannot be used as a migration destination.');
            }

            if (is_file($path) && filesize($path) > 0) {
                throw new RuntimeException('The destination SQLite database file must not exist or must be empty.');
            }

            return;
        }

        config(['database.connections.'.self::CandidateConnection => ApplicationDatabaseBootstrap::connection($payload, base_path())]);
        DB::purge(self::CandidateConnection);

        try {
            DB::connection(self::CandidateConnection)->getPdo();

            if (Schema::connection(self::CandidateConnection)->getTableListing() !== []) {
                throw new RuntimeException('The destination database must be dedicated and empty.');
            }
        } finally {
            DB::purge(self::CandidateConnection);
            config()->offsetUnset('database.connections.'.self::CandidateConnection);
        }
    }

    /** @return array<string, mixed> */
    private function transition(string $id, ApplicationDatabaseMigrationStatus $status, string $event): array
    {
        return $this->store->update($id, function (array $state) use ($status, $event): array {
            $state['status'] = $status->value;
            $state['events'][] = ['at' => now()->toIso8601String(), 'event' => $event];

            return $state;
        });
    }

    private function assertManagedConfiguration(): void
    {
        if (config('database.control_metadata.mode') !== ApplicationDatabaseBootstrap::ManagedMode) {
            throw new RuntimeException('Cutover requires CRUCIBLE_DATABASE_CONFIG_MODE=managed.');
        }
    }

    private function assertFenceOwnedBy(string $id): void
    {
        $active = $this->fence->active();

        if ($active === null || $active['plan_id'] !== $id) {
            throw new RuntimeException('This migration does not hold the application maintenance fence.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function writeActiveConfiguration(array $payload): void
    {
        ApplicationDatabaseBootstrap::write(
            $payload,
            (string) config('database.control_metadata.path'),
            (string) config('app.key'),
            (string) config('app.cipher'),
        );
    }
}
