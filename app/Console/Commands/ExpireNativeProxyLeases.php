<?php

namespace App\Console\Commands;

use App\Services\ApplicationDatabaseMigrationFence;
use App\Services\NativeProxy\LeaseWorkflow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crucible:expire-native-proxy-leases')]
#[Description('Expire native client leases and revoke their active credentials.')]
class ExpireNativeProxyLeases extends Command
{
    public function handle(LeaseWorkflow $leaseWorkflow, ApplicationDatabaseMigrationFence $fence): int
    {
        $count = 0;
        $ran = $fence->runScheduledMutation(function () use ($leaseWorkflow, &$count): void {
            $count = $leaseWorkflow->expireDue();
        });

        if (! $ran) {
            $this->info('Skipped lease expiration while an application database migration is fenced.');

            return self::SUCCESS;
        }

        $this->info("Expired {$count} native proxy lease(s).");

        return self::SUCCESS;
    }
}
