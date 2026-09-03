<?php

namespace App\Console\Commands;

use App\Services\ApplicationDatabaseMigrationManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('crucible:application-database:activate {migration?} {--finalize : Release the fence after all processes restarted} {--force : Skip confirmation}')]
#[Description('Atomically activate a verified application database, then finalize after a full process restart.')]
class ActivateApplicationDatabaseMigration extends Command
{
    public function handle(ApplicationDatabaseMigrationManager $manager): int
    {
        if (! $this->option('force') && ! $this->confirm($this->option('finalize')
            ? 'Have the app, Horizon worker, and scheduler all restarted on the destination database?'
            : 'Activate the verified destination configuration? The application stays fenced until restart finalization.')) {
            return self::FAILURE;
        }

        try {
            $state = $manager->activate($this->migrationId(), (bool) $this->option('finalize'));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($state['status'] === 'activation_pending_restart') {
            $this->components->warn('Restart every app, Horizon worker, and scheduler process now.');
            $this->line('Then run: php artisan crucible:application-database:activate '.$state['id'].' --finalize');
        } else {
            $this->components->info('Destination activated and maintenance fence released. The source database was retained for rollback.');
        }

        return self::SUCCESS;
    }

    private function migrationId(): ?string
    {
        $value = $this->argument('migration');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
