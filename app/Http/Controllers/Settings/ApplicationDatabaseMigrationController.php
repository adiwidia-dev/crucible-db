<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ApplicationDatabaseDriver;
use App\Enums\ApplicationDatabaseMigrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmApplicationDatabaseMigrationRequest;
use App\Http\Requests\PlanApplicationDatabaseMigrationRequest;
use App\Http\Requests\RunApplicationDatabaseMigrationRequest;
use App\Models\User;
use App\Services\ApplicationDatabaseConfiguration;
use App\Services\ApplicationDatabaseMigrationManager;
use App\Services\AuditLogger;
use App\Support\ApplicationDatabaseMigrationStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ApplicationDatabaseMigrationController extends Controller
{
    public function edit(
        ApplicationDatabaseConfiguration $configuration,
        ApplicationDatabaseMigrationManager $manager,
        ApplicationDatabaseMigrationStore $store,
    ): Response {
        abort_unless(request()->user()->isAdmin(), 403);

        $migration = null;
        $migrationHistory = [];
        $migrationError = null;

        try {
            $currentId = $store->currentId();

            if ($currentId !== null) {
                $currentState = $store->read($currentId);
                $currentStatus = ApplicationDatabaseMigrationStatus::from((string) $currentState['status']);

                if (! $currentStatus->isTerminal()) {
                    $migration = $manager->inspect();
                    unset(
                        $migration['active_fingerprint'],
                        $migration['source']['fingerprint'],
                        $migration['destination']['fingerprint'],
                    );
                }
            }

            $migrationHistory = array_values(array_map(
                fn (array $state): array => $this->migrationHistorySummary(
                    $state,
                    (string) $state['id'] === $currentId,
                ),
                array_filter(
                    $store->all(),
                    static function (array $state) use ($currentId): bool {
                        $status = ApplicationDatabaseMigrationStatus::from((string) $state['status']);

                        return (string) $state['id'] !== $currentId || $status->isTerminal();
                    },
                ),
            ));
        } catch (Throwable $exception) {
            report($exception);
            $migrationError = $exception->getMessage();
        }

        $active = $configuration->activePayload();
        $activeDriver = ApplicationDatabaseDriver::from((string) $active['driver']);

        return Inertia::render('settings/admin/application-database', [
            'configuration_mode' => config('database.control_metadata.mode'),
            'active_database' => [
                'driver' => $activeDriver->value,
                'driver_label' => $activeDriver->label(),
                'database' => $active['database'] ?? null,
                'host' => $active['host'] ?? null,
                'port' => $active['port'] ?? null,
            ],
            'migration' => $migration,
            'migration_history' => $migrationHistory,
            'migration_error' => $migrationError,
            'drivers' => array_map(fn (ApplicationDatabaseDriver $driver): array => [
                'value' => $driver->value,
                'label' => $driver->label(),
                'default_port' => $driver->defaultPort(),
            ], ApplicationDatabaseDriver::cases()),
            'sqlite_path' => storage_path('database/crucible-migrated.sqlite'),
            'confirmations' => [
                'activate' => ConfirmApplicationDatabaseMigrationRequest::ActivatePhrase,
                'finalize' => ConfirmApplicationDatabaseMigrationRequest::FinalizePhrase,
                'rollback' => ConfirmApplicationDatabaseMigrationRequest::RollbackPhrase,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function migrationHistorySummary(array $state, bool $isCurrent): array
    {
        $status = ApplicationDatabaseMigrationStatus::from((string) $state['status']);

        return [
            'id' => (string) $state['id'],
            'status' => $status->value,
            'source' => [
                'driver' => (string) $state['source']['driver'],
                'database' => $state['source']['database'] ?? null,
            ],
            'destination' => [
                'driver' => (string) $state['destination']['driver'],
                'database' => $state['destination']['database'] ?? null,
            ],
            'tables_copied' => count((array) ($state['tables'] ?? [])),
            'tables_planned' => count((array) ($state['planned_tables'] ?? [])),
            'created_at' => (string) $state['created_at'],
            'updated_at' => (string) $state['updated_at'],
            'is_current' => $isCurrent,
            'can_rollback' => $isCurrent && $status === ApplicationDatabaseMigrationStatus::Active,
        ];
    }

    public function store(
        PlanApplicationDatabaseMigrationRequest $request,
        ApplicationDatabaseMigrationManager $manager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        return $this->perform(function () use ($auditLogger, $manager, $request): void {
            $state = $manager->plan($request->destinationPayload());
            $manager->recordOperatorEvent((string) $state['id'], 'admin_plan_created', $request->user());
            $auditLogger->log('application_database_migration.planned', $request->user(), null, [
                'plan_id' => $state['id'],
                'source_driver' => $state['source']['driver'],
                'destination_driver' => $state['destination']['driver'],
            ]);
        }, 'Migration plan created. Review the checks before starting the copy.');
    }

    public function migrate(
        RunApplicationDatabaseMigrationRequest $request,
        string $migration,
        ApplicationDatabaseMigrationManager $manager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        return $this->perform(function () use ($auditLogger, $manager, $migration, $request): void {
            $manager->recordOperatorEvent($migration, 'admin_copy_requested', $request->user());
            $auditLogger->log('application_database_migration.copy_requested', $request->user(), null, [
                'plan_id' => $migration,
            ]);
            $manager->migrate($migration, (int) $request->validated('drain_timeout_seconds'));
        }, 'Application data copied. Verify it before cutover.');
    }

    public function destroy(
        Request $request,
        string $migration,
        ApplicationDatabaseMigrationManager $manager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $actor = $this->admin($request);

        return $this->perform(function () use ($actor, $auditLogger, $manager, $migration): void {
            $state = $manager->cancel($migration);
            $auditLogger->log('application_database_migration.cancelled', $actor, null, [
                'plan_id' => $migration,
                'destination_driver' => $state['destination']['driver'],
            ]);
        }, 'Migration plan cancelled. The active application database was not changed.');
    }

    public function verify(Request $request, string $migration, ApplicationDatabaseMigrationManager $manager): RedirectResponse
    {
        $actor = $this->admin($request);

        return $this->perform(function () use ($actor, $manager, $migration): void {
            $manager->recordOperatorEvent($migration, 'admin_verification_requested', $actor);
            $manager->verify($migration);
        }, 'Source and destination data match.');
    }

    public function activate(
        ConfirmApplicationDatabaseMigrationRequest $request,
        string $migration,
        ApplicationDatabaseMigrationManager $manager,
    ): RedirectResponse {
        return $this->perform(function () use ($manager, $migration, $request): void {
            $manager->recordOperatorEvent($migration, 'admin_activation_requested', $request->user());
            $manager->activate($migration, false);
        }, 'Cutover prepared. Restart every Crucible runtime process, then finalize activation.');
    }

    public function finalizeActivation(
        ConfirmApplicationDatabaseMigrationRequest $request,
        string $migration,
        ApplicationDatabaseMigrationManager $manager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        return $this->perform(function () use ($auditLogger, $manager, $migration, $request): void {
            $manager->recordOperatorEvent($migration, 'admin_activation_finalization_requested', $request->user());
            $state = $manager->activate($migration, true);
            $auditLogger->log('application_database_migration.activated', $request->user(), null, [
                'plan_id' => $migration,
                'driver' => $state['destination']['driver'],
            ]);
        }, 'Application database migration completed.');
    }

    public function rollback(
        ConfirmApplicationDatabaseMigrationRequest $request,
        string $migration,
        ApplicationDatabaseMigrationManager $manager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        return $this->perform(function () use ($auditLogger, $manager, $migration, $request): void {
            $manager->recordOperatorEvent($migration, 'admin_rollback_requested', $request->user());
            $auditLogger->log('application_database_migration.rollback_requested', $request->user(), null, [
                'plan_id' => $migration,
            ]);
            $manager->rollback($migration, false);
        }, 'Rollback data synchronized. Restart every Crucible runtime process, then finalize rollback.');
    }

    public function finalizeRollback(
        ConfirmApplicationDatabaseMigrationRequest $request,
        string $migration,
        ApplicationDatabaseMigrationManager $manager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        return $this->perform(function () use ($auditLogger, $manager, $migration, $request): void {
            $manager->recordOperatorEvent($migration, 'admin_rollback_finalization_requested', $request->user());
            $state = $manager->rollback($migration, true);
            $auditLogger->log('application_database_migration.rolled_back', $request->user(), null, [
                'plan_id' => $migration,
                'driver' => $state['source']['driver'],
            ]);
        }, 'Rollback completed and the original application database is active.');
    }

    /** @param callable(): void $operation */
    private function perform(callable $operation, string $successMessage): RedirectResponse
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'migration_operation' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $successMessage]);

        return back();
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->isAdmin(), 403);

        return $user;
    }
}
