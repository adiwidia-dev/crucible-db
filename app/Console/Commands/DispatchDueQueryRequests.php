<?php

namespace App\Console\Commands;

use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Models\QueryRequest;
use App\Services\ApplicationDatabaseMigrationFence;
use App\Services\QueryRequestWorkflow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crucible:dispatch-due-query-requests')]
#[Description('Dispatch approved query requests whose scheduled execution time is due.')]
class DispatchDueQueryRequests extends Command
{
    public function handle(QueryRequestWorkflow $workflow, ApplicationDatabaseMigrationFence $fence): int
    {
        $count = 0;

        $ran = $fence->runScheduledMutation(function () use ($workflow, &$count): void {
            QueryRequest::query()
                ->where('request_kind', QueryRequestKind::SingleExecution->value)
                ->where('status', QueryRequestStatus::Scheduled->value)
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '<=', now())
                ->orderBy('scheduled_at')
                ->each(function (QueryRequest $queryRequest) use ($workflow, &$count): void {
                    $workflow->dispatch($queryRequest);
                    $count++;
                });
        });

        if (! $ran) {
            $this->info('Skipped due query dispatch while an application database migration is fenced.');

            return self::SUCCESS;
        }

        $this->info("Dispatched {$count} due query request(s).");

        return self::SUCCESS;
    }
}
