<?php

namespace App\Console\Commands;

use App\Enums\QueryRequestStatus;
use App\Models\QuerySession;
use App\Services\ApplicationDatabaseMigrationFence;
use App\Services\AuditLogger;
use App\Services\NativeProxy\LeaseWorkflow;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crucible:expire-query-sessions')]
#[Description('Mark expired query access sessions as ended and complete their source requests.')]
class ExpireQuerySessions extends Command
{
    public function handle(
        AuditLogger $auditLogger,
        NotificationDispatcher $notificationDispatcher,
        LeaseWorkflow $leaseWorkflow,
        ApplicationDatabaseMigrationFence $fence,
    ): int {
        $count = 0;

        $ran = $fence->runScheduledMutation(function () use ($auditLogger, $leaseWorkflow, $notificationDispatcher, &$count): void {
            QuerySession::query()
                ->with('queryRequest')
                ->whereNull('ended_at')
                ->where('expires_at', '<=', now())
                ->orderBy('expires_at')
                ->each(function (QuerySession $querySession) use ($auditLogger, $notificationDispatcher, $leaseWorkflow, &$count): void {
                    $endedAt = now();

                    $querySession->forceFill([
                        'ended_at' => $endedAt,
                    ])->save();

                    if ($querySession->queryRequest->status === QueryRequestStatus::Running) {
                        $querySession->queryRequest->forceFill([
                            'status' => QueryRequestStatus::Completed,
                            'completed_at' => $endedAt,
                        ])->save();
                    }

                    $auditLogger->log('query_session.expired', null, $querySession, [
                        'query_request_id' => $querySession->query_request_id,
                        'expires_at' => $querySession->expires_at->toIso8601String(),
                    ]);
                    $leaseWorkflow->revokeForQuerySession($querySession, null, 'Query access session expired.');

                    $notificationDispatcher->sessionExpired($querySession);

                    $count++;
                });
        });

        if (! $ran) {
            $this->info('Skipped session expiration while an application database migration is fenced.');

            return self::SUCCESS;
        }

        $this->info("Expired {$count} query session(s).");

        return self::SUCCESS;
    }
}
