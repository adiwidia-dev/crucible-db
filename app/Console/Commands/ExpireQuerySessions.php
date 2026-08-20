<?php

namespace App\Console\Commands;

use App\Enums\QueryRequestStatus;
use App\Models\QuerySession;
use App\Services\AuditLogger;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crucible:expire-query-sessions')]
#[Description('Mark expired query access sessions as ended and complete their source requests.')]
class ExpireQuerySessions extends Command
{
    public function handle(AuditLogger $auditLogger, NotificationDispatcher $notificationDispatcher): int
    {
        $count = 0;

        QuerySession::query()
            ->with('queryRequest')
            ->whereNull('ended_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->each(function (QuerySession $querySession) use ($auditLogger, $notificationDispatcher, &$count): void {
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

                $notificationDispatcher->sessionExpired($querySession);

                $count++;
            });

        $this->info("Expired {$count} query session(s).");

        return self::SUCCESS;
    }
}
