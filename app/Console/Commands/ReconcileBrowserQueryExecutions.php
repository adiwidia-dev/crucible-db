<?php

namespace App\Console\Commands;

use App\Enums\ExecutionStatus;
use App\Models\QuerySessionQuery;
use App\Services\ApplicationDatabaseMigrationFence;
use App\Services\QuerySessionWorkflow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('crucible:reconcile-browser-query-executions')]
#[Description('Cancel timed-out browser queries and reconcile abandoned queue state.')]
class ReconcileBrowserQueryExecutions extends Command
{
    public function handle(
        ApplicationDatabaseMigrationFence $fence,
        QuerySessionWorkflow $workflow,
    ): int {
        $ran = $fence->runScheduledMutation(function () use ($workflow): bool {
            $queuedCutoff = now()->subSeconds((int) config('query_access.queue_stale_seconds'));
            $staleQueued = QuerySessionQuery::query()
                ->whereNull('native_proxy_connection_id')
                ->where('status', ExecutionStatus::Queued)
                ->where('created_at', '<=', $queuedCutoff)
                ->get();

            $staleQueued->each(function (QuerySessionQuery $query) use ($workflow): void {
                $workflow->markDispatchFailed($query->id);
            });

            $graceSeconds = (int) config('query_access.reconciliation_grace_seconds');
            $staleActive = QuerySessionQuery::query()
                ->whereNull('native_proxy_connection_id')
                ->whereIn('status', [ExecutionStatus::Running, ExecutionStatus::Cancelling])
                ->get()
                ->filter(fn (QuerySessionQuery $query): bool => $query->started_at !== null
                    && $query->started_at
                        ->copy()
                        ->addSeconds(($query->timeout_seconds ?? 0) + $graceSeconds)
                        ->isPast());

            $staleActive->each(function (QuerySessionQuery $query) use ($workflow): void {
                $workflow->markInterrupted(
                    $query->id,
                    new RuntimeException('Browser query execution became stale.'),
                    true,
                );
            });

            $this->info('Reconciled '.$staleQueued->count().' queued and '.$staleActive->count().' active browser query execution(s).');

            return true;
        });

        if (! $ran) {
            $this->info('Skipped browser query reconciliation while an application database migration is fenced.');
        }

        return self::SUCCESS;
    }
}
