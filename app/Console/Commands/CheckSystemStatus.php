<?php

namespace App\Console\Commands;

use App\Services\ApplicationDatabaseMigrationFence;
use App\Services\SystemStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crucible:check-system-status')]
#[Description('Refresh cached infrastructure status for the administrator system status page.')]
class CheckSystemStatus extends Command
{
    public function handle(SystemStatus $systemStatus, ApplicationDatabaseMigrationFence $fence): int
    {
        $snapshot = null;
        $ran = $fence->runScheduledMutation(function () use ($systemStatus, &$snapshot): void {
            $snapshot = $systemStatus->refresh();
        });

        if (! $ran || $snapshot === null) {
            $this->info('Skipped system status refresh while an application database migration is fenced.');

            return self::SUCCESS;
        }

        $this->info('System status refreshed.');

        return self::SUCCESS;
    }
}
