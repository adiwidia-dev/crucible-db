<?php

namespace App\Console\Commands;

use App\Services\ApplicationDatabaseMigrationManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('crucible:application-database:rollback
    {migration?}
    {--finalize : Release the fence after all processes restarted}
    {--drain-timeout=300 : Seconds to wait for queued work to drain}
    {--force : Skip confirmation}')]
#[Description('Restore the retained source database configuration for an activated migration.')]
class RollbackApplicationDatabaseMigration extends Command
{
    public function handle(ApplicationDatabaseMigrationManager $manager): int
    {
        if (! $this->option('force') && ! $this->confirm($this->option('finalize')
            ? 'Have the app, Horizon worker, and scheduler all restarted on the retained source database?'
            : 'Fence the application and restore the retained source configuration?')) {
            return self::FAILURE;
        }

        try {
            $state = $manager->rollback(
                $this->migrationId(),
                (bool) $this->option('finalize'),
                max(0, (int) $this->option('drain-timeout')),
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($state['status'] === 'rollback_pending_restart') {
            $this->components->warn('Restart every app, Horizon worker, and scheduler process now.');
            $this->line('Then run: php artisan crucible:application-database:rollback '.$state['id'].' --finalize');
        } else {
            $this->components->info('Source configuration restored and maintenance fence released. The destination was retained.');
        }

        return self::SUCCESS;
    }

    private function migrationId(): ?string
    {
        $value = $this->argument('migration');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
