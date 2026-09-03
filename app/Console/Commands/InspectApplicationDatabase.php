<?php

namespace App\Console\Commands;

use App\Services\ApplicationDatabaseConfiguration;
use App\Services\ApplicationDatabaseMigrationFence;
use App\Services\ApplicationDatabaseMigrationManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('crucible:application-database:inspect {migration? : Migration plan ULID; defaults to the current plan}')]
#[Description('Inspect the active application database and an optional migration plan without exposing credentials.')]
class InspectApplicationDatabase extends Command
{
    public function handle(
        ApplicationDatabaseConfiguration $configuration,
        ApplicationDatabaseMigrationFence $fence,
        ApplicationDatabaseMigrationManager $manager,
    ): int {
        $active = $configuration->activePayload();
        $this->components->twoColumnDetail('Configuration mode', (string) config('database.control_metadata.mode'));
        $this->components->twoColumnDetail('Active driver', (string) $active['driver']);
        $this->components->twoColumnDetail('Active database', (string) ($active['database'] ?? 'configured by URL'));
        $this->components->twoColumnDetail('Active fingerprint', $configuration->fingerprint($active));
        $this->components->twoColumnDetail('Maintenance fence', $fence->isActive() ? 'engaged' : 'open');

        try {
            $state = $manager->inspect($this->optionalMigrationId());
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'No application database migration plan exists')) {
                $this->newLine();
                $this->components->info('No migration plan exists.');

                return self::SUCCESS;
            }

            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('Migration', (string) $state['id']);
        $this->components->twoColumnDetail('Status', (string) $state['status']);
        $this->components->twoColumnDetail('Source', $state['source']['driver'].' / '.($state['source']['database'] ?? 'URL'));
        $this->components->twoColumnDetail('Destination', $state['destination']['driver'].' / '.($state['destination']['database'] ?? 'URL'));
        $this->components->twoColumnDetail('Verified tables', (string) count($state['tables'] ?? []));
        $this->components->twoColumnDetail('Active query sessions', (string) $state['activity']['query_sessions']);
        $this->components->twoColumnDetail('Active native leases', (string) $state['activity']['native_leases']);
        $this->components->twoColumnDetail('Active native connections', (string) $state['activity']['native_connections']);

        return self::SUCCESS;
    }

    private function optionalMigrationId(): ?string
    {
        $value = $this->argument('migration');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
