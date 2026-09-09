<?php

namespace App\Console\Commands;

use App\Services\ApplicationDatabaseMigrationFence;
use App\Services\NativeProxy\ProxyHealth;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crucible:check-native-proxy-health')]
#[Description('Refresh the bounded native proxy readiness snapshot and notify operators on failure.')]
class CheckNativeProxyHealth extends Command
{
    public function handle(ProxyHealth $proxyHealth, NotificationDispatcher $notificationDispatcher, ApplicationDatabaseMigrationFence $fence): int
    {
        $snapshot = null;
        $ran = $fence->runScheduledMutation(function () use ($notificationDispatcher, $proxyHealth, &$snapshot): void {
            $previousSnapshot = $proxyHealth->latest();
            $snapshot = $proxyHealth->refresh();

            if (in_array($snapshot['status'], ['unhealthy', 'version_mismatch'], true) && $snapshot['status'] !== $previousSnapshot['status']) {
                $notificationDispatcher->nativeProxyHealthChanged($snapshot);
            }
        });

        if (! $ran || $snapshot === null) {
            $this->info('Skipped native proxy health refresh while an application database migration is fenced.');

            return self::SUCCESS;
        }

        $this->info("Native proxy health: {$snapshot['status']}.");

        return self::SUCCESS;
    }
}
