<?php

namespace App\Console\Commands;

use App\Enums\ApplicationDatabaseDriver;
use App\Services\ApplicationDatabaseMigrationManager;
use App\Support\ApplicationDatabaseBootstrap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Signature('crucible:application-database:plan
    {--driver= : Destination driver: sqlite, pgsql, or mysql}
    {--database= : Destination database name or SQLite file path}
    {--host=127.0.0.1 : Destination network host}
    {--port= : Destination network port}
    {--username= : Destination network username}
    {--password-env=CRUCIBLE_MIGRATION_DB_PASSWORD : Environment variable containing the destination password}
    {--pgsql-sslmode=prefer : PostgreSQL SSL mode}
    {--mysql-ssl-ca= : MySQL CA certificate path}')]
#[Description('Create an encrypted, non-destructive application database migration plan.')]
class PlanApplicationDatabaseMigration extends Command
{
    public function handle(ApplicationDatabaseMigrationManager $manager): int
    {
        try {
            $state = $manager->plan($this->destinationPayload());

            $id = $state['id'] ?? null;
            $plannedTables = $state['planned_tables'] ?? null;

            if (! is_string($id) || ! is_array($plannedTables)) {
                throw new RuntimeException('The created migration plan is invalid.');
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Migration plan created. No data was copied and the active database was not changed.');
        $this->components->twoColumnDetail('Migration', $id);
        $this->components->twoColumnDetail('Tables', (string) count($plannedTables));
        $this->components->twoColumnDetail('Next', 'crucible:application-database:migrate '.$id);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function destinationPayload(): array
    {
        $driverValue = $this->stringOption('driver');
        $driver = ApplicationDatabaseDriver::tryFrom($driverValue)
            ?? throw new RuntimeException('The --driver option must be sqlite, pgsql, or mysql.');
        $database = trim($this->stringOption('database'));

        if ($database === '') {
            throw new RuntimeException('The --database option is required.');
        }

        if ($driver === ApplicationDatabaseDriver::Sqlite) {
            return [
                'version' => ApplicationDatabaseBootstrap::CurrentVersion,
                'driver' => $driver->value,
                'database' => $database,
                'foreign_key_constraints' => true,
                'busy_timeout' => 5000,
                'journal_mode' => 'WAL',
                'synchronous' => 'FULL',
                'transaction_mode' => 'IMMEDIATE',
            ];
        }

        $username = trim($this->stringOption('username'));

        if ($username === '') {
            throw new RuntimeException('The --username option is required for a network database.');
        }

        $passwordEnvironment = trim($this->stringOption('password-env'));
        $password = $passwordEnvironment === '' ? false : getenv($passwordEnvironment);

        if ($password === false && $this->input->isInteractive()) {
            $password = $this->secret('Destination database password');
        }

        if (! is_string($password)) {
            throw new RuntimeException("Set {$passwordEnvironment} or run interactively to provide the destination password securely.");
        }

        $portValue = $this->stringOption('port');
        $port = $portValue === '' ? $driver->defaultPort() : filter_var($portValue, FILTER_VALIDATE_INT);

        if (! is_int($port) || $port < 1 || $port > 65535) {
            throw new RuntimeException('The destination port must be between 1 and 65535.');
        }

        $payload = [
            'version' => ApplicationDatabaseBootstrap::CurrentVersion,
            'driver' => $driver->value,
            'host' => trim($this->stringOption('host')),
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
        ];

        if ($driver === ApplicationDatabaseDriver::PostgreSql) {
            $payload['pgsql_sslmode'] = $this->stringOption('pgsql-sslmode');
        } else {
            $sslCa = trim($this->stringOption('mysql-ssl-ca'));

            if ($sslCa !== '') {
                $payload['mysql_ssl_ca'] = $sslCa;
            }
        }

        return $payload;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        if ($value === null) {
            return '';
        }

        if (! is_string($value)) {
            throw new RuntimeException("The --{$name} option must be a string.");
        }

        return $value;
    }
}
