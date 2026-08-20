<?php

namespace App\Jobs;

use App\Enums\ExecutionStatus;
use App\Enums\PreflightStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Models\QueryExecution;
use App\Models\QueryRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DatabaseQueryExecutor;
use App\Services\DeploymentPreflight;
use App\Services\NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ExecuteQueryRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(
        public int $queryRequestId,
        public ?int $resumeFromPosition = null,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("query-request:{$this->queryRequestId}"))
                ->expireAfter($this->timeout + 30)
                ->dontRelease(),
        ];
    }

    public function handle(
        DatabaseQueryExecutor $executor,
        AuditLogger $auditLogger,
        DeploymentPreflight $deploymentPreflight,
        NotificationDispatcher $notificationDispatcher,
    ): void {
        $queryRequest = QueryRequest::query()
            ->with('databaseConnection', 'requester', 'dispatchedBy', 'statements.databaseConnection')
            ->findOrFail($this->queryRequestId);

        if ($queryRequest->request_kind !== QueryRequestKind::SingleExecution) {
            return;
        }

        if (! in_array($queryRequest->status, [QueryRequestStatus::Approved, QueryRequestStatus::Running], true)) {
            return;
        }

        $executorUser = $queryRequest->dispatchedBy ?? $queryRequest->requester;
        $preflight = $deploymentPreflight->evaluate($queryRequest);
        $deploymentPreflight->persist($queryRequest, $preflight);

        if ($preflight['status'] === PreflightStatus::Blocked) {
            $queryRequest->forceFill([
                'status' => QueryRequestStatus::Approved,
                'dispatched_at' => null,
                'dispatched_by_id' => null,
            ])->save();

            $auditLogger->log('query_request.preflight_blocked', $executorUser, $queryRequest, [
                'trigger' => 'execution_guard',
                'blocker_count' => $preflight['summary']['blocker_count'],
            ]);

            if ($queryRequest->scheduled_at?->isPast()) {
                $notificationDispatcher->scheduledBatchPreflightBlocked($queryRequest);
            }

            return;
        }

        $queryRequest->forceFill([
            'status' => QueryRequestStatus::Running,
            'last_error' => null,
        ])->save();

        $statements = $queryRequest->statements
            ->filter(fn ($statement): bool => $statement->position >= ($this->resumeFromPosition ?? 1))
            ->map(fn ($statement): array => [
                'id' => $statement->id,
                'position' => $statement->position,
                'database_connection' => $statement->databaseConnection ?? $queryRequest->databaseConnection,
                'sql' => $statement->sql,
                'query_type' => $statement->query_type,
            ])
            ->values();

        if ($statements->isEmpty()) {
            $statements->push([
                'id' => null,
                'position' => 1,
                'database_connection' => $queryRequest->databaseConnection,
                'sql' => $queryRequest->sql,
                'query_type' => $queryRequest->query_type,
            ]);
        }

        $executionIds = [];
        $statementResults = [];
        $totalRowCount = 0;
        $resultTruncated = false;

        foreach ($statements as $statement) {
            if ($this->hasBeenCancelled($queryRequest)) {
                $this->recordCancellationAcknowledgement($auditLogger, $executorUser, $queryRequest);

                return;
            }

            $startedAt = now();
            $execution = QueryExecution::query()->create([
                'query_request_id' => $queryRequest->id,
                'query_request_statement_id' => $statement['id'],
                'database_connection_id' => $statement['database_connection']->id,
                'executed_by_id' => $executorUser->id,
                'sql' => $statement['sql'],
                'query_type' => $statement['query_type'],
                'status' => ExecutionStatus::Running,
                'started_at' => $startedAt,
            ]);
            $executionIds[] = $execution->id;

            try {
                $result = $executor->execute($statement['database_connection'], $statement['sql'], $statement['query_type']);
                $finishedAt = now();

                $execution->forceFill([
                    'status' => ExecutionStatus::Succeeded,
                    'finished_at' => $finishedAt,
                    'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
                    'row_count' => $result['row_count'],
                    'result_truncated' => $result['result_truncated'] ?? false,
                    'sample_rows' => $result['sample_rows'],
                ])->save();

                $totalRowCount += $result['row_count'];
                $resultTruncated = $resultTruncated || ($result['result_truncated'] ?? false);
                $statementResults[] = [
                    'position' => $statement['position'],
                    'execution_id' => $execution->id,
                    'row_count' => $result['row_count'],
                    'result_truncated' => $result['result_truncated'] ?? false,
                ];

                $auditLogger->log('query_request.statement_executed', $executorUser, $queryRequest, [
                    'statement_position' => $statement['position'],
                    'database_connection_id' => $statement['database_connection']->id,
                    'execution_id' => $execution->id,
                    'row_count' => $result['row_count'],
                    'result_truncated' => $result['result_truncated'] ?? false,
                ]);
            } catch (Throwable $exception) {
                $finishedAt = now();

                $execution->forceFill([
                    'status' => ExecutionStatus::Failed,
                    'finished_at' => $finishedAt,
                    'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
                    'error_message' => $exception->getMessage(),
                ])->save();

                if ($this->hasBeenCancelled($queryRequest)) {
                    $this->recordCancellationAcknowledgement($auditLogger, $executorUser, $queryRequest);

                    return;
                }

                $queryRequest->forceFill([
                    'status' => QueryRequestStatus::Failed,
                    'completed_at' => $finishedAt,
                    'last_error' => $exception->getMessage(),
                    'result_summary' => [
                        'statement_count' => $statements->count(),
                        'completed_statement_count' => count($statementResults),
                        'failed_statement_position' => $statement['position'],
                        'row_count' => $totalRowCount,
                        'result_truncated' => $resultTruncated,
                        'statements' => $statementResults,
                    ],
                ])->save();

                $auditLogger->log('query_request.execution_failed', $executorUser, $queryRequest, [
                    'statement_position' => $statement['position'],
                    'database_connection_id' => $statement['database_connection']->id,
                    'execution_id' => $execution->id,
                    'error' => $exception->getMessage(),
                ]);

                $notificationDispatcher->batchFailed($queryRequest, $statement['position']);

                throw $exception;
            }
        }

        if ($this->hasBeenCancelled($queryRequest)) {
            $this->recordCancellationAcknowledgement($auditLogger, $executorUser, $queryRequest);

            return;
        }

        $finishedAt = now();

        $queryRequest->forceFill([
            'status' => QueryRequestStatus::Completed,
            'completed_at' => $finishedAt,
            'result_summary' => [
                'statement_count' => $statements->count(),
                'completed_statement_count' => count($statementResults),
                'row_count' => $totalRowCount,
                'result_truncated' => $resultTruncated,
                'statements' => $statementResults,
            ],
        ])->save();

        $auditLogger->log('query_request.executed', $executorUser, $queryRequest, [
            'execution_ids' => $executionIds,
            'statement_count' => $statements->count(),
            'row_count' => $totalRowCount,
            'result_truncated' => $resultTruncated,
        ]);

        $notificationDispatcher->batchCompleted($queryRequest);
    }

    public function failed(?Throwable $exception): void
    {
        $queryRequest = QueryRequest::query()->find($this->queryRequestId);

        if ($queryRequest instanceof QueryRequest && ! $queryRequest->isTerminal()) {
            $queryRequest->forceFill([
                'status' => QueryRequestStatus::Failed,
                'completed_at' => now(),
                'last_error' => $exception?->getMessage() ?? 'Query execution failed.',
            ])->save();

            app(NotificationDispatcher::class)->batchFailed($queryRequest);
        }
    }

    /**
     * @phpstan-impure
     */
    private function hasBeenCancelled(QueryRequest $queryRequest): bool
    {
        return $queryRequest->fresh()?->status === QueryRequestStatus::Cancelled;
    }

    private function recordCancellationAcknowledgement(AuditLogger $auditLogger, User $executorUser, QueryRequest $queryRequest): void
    {
        $auditLogger->log('query_request.execution_stop_acknowledged', $executorUser, $queryRequest, [
            'resume_from_statement_position' => $this->resumeFromPosition,
        ]);
    }
}
