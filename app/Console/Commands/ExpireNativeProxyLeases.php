<?php

namespace App\Console\Commands;

use App\Services\NativeProxy\LeaseWorkflow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crucible:expire-native-proxy-leases')]
#[Description('Expire native client leases and revoke their active credentials.')]
class ExpireNativeProxyLeases extends Command
{
    public function handle(LeaseWorkflow $leaseWorkflow): int
    {
        $count = $leaseWorkflow->expireDue();

        $this->info("Expired {$count} native proxy lease(s).");

        return self::SUCCESS;
    }
}
