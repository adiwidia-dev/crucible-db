<?php

namespace App\Console\Commands;

use App\Services\NativeProxy\ProxyHealth;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crucible:check-native-proxy-health')]
#[Description('Refresh the bounded native proxy readiness snapshot and notify operators on failure.')]
class CheckNativeProxyHealth extends Command
{
    public function handle(ProxyHealth $proxyHealth, NotificationDispatcher $notificationDispatcher): int
    {
        $previousSnapshot = $proxyHealth->latest();
        $snapshot = $proxyHealth->refresh();

        if (in_array($snapshot['status'], ['unhealthy', 'version_mismatch'], true) && $snapshot['status'] !== $previousSnapshot['status']) {
            $notificationDispatcher->nativeProxyHealthChanged($snapshot);
        }

        $this->info("Native proxy health: {$snapshot['status']}.");

        return self::SUCCESS;
    }
}
