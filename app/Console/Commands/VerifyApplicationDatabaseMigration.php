<?php

namespace App\Console\Commands;

use App\Services\ApplicationDatabaseMigrationManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('crucible:application-database:verify {migration?}')]
#[Description('Re-read and compare every durable source and destination table under the migration fence.')]
class VerifyApplicationDatabaseMigration extends Command
{
    public function handle(ApplicationDatabaseMigrationManager $manager): int
    {
        try {
            $state = $manager->verify($this->migrationId());
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Source and destination data match.');
        $this->components->twoColumnDetail('Verified tables', (string) count($state['tables']));
        $this->components->twoColumnDetail('Next', 'crucible:application-database:activate '.$state['id']);

        return self::SUCCESS;
    }

    private function migrationId(): ?string
    {
        $value = $this->argument('migration');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
