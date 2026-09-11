<?php

namespace App\Console\Commands;

use App\Services\ApplicationDatabaseMigrationManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('crucible:application-database:migrate {migration?} {--drain-timeout=300 : Seconds to wait for queued work to drain}')]
#[Description('Fence the application, drain work, and copy the control database to a planned destination.')]
class MigrateApplicationDatabase extends Command
{
    public function handle(ApplicationDatabaseMigrationManager $manager): int
    {
        try {
            $state = $manager->migrate($this->migrationId(), max(0, (int) $this->option('drain-timeout')));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());
            $this->components->warn('The active application database was not changed. Inspect the migration and maintenance-fence state before retrying.');

            return self::FAILURE;
        }

        $this->components->info('The destination copy completed. The application remains fenced.');
        $this->components->twoColumnDetail('Verified tables', (string) count($state['tables']));
        $this->components->twoColumnDetail('Next', 'crucible:application-database:verify '.$state['id']);

        return self::SUCCESS;
    }

    private function migrationId(): ?string
    {
        $value = $this->argument('migration');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
