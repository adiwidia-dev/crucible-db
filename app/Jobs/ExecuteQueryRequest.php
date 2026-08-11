<?php

namespace App\Jobs;

use App\Enums\ExecutionStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Models\QueryExecution;
use App\Models\QueryRequest;
use App\Services\AuditLogger;
use App\Services\DatabaseQueryExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteQueryRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $queryRequestId) {}

    public function handle(DatabaseQueryExecutor $executor, AuditLogger $auditLogger): void
    {
        $queryRequest = QueryRequest::query()
            ->with('databaseConnection', 'requester', 'dispatchedBy')
            ->findOrFail($this->queryRequestId);

        if ($queryRequest->request_kind !== QueryRequestKind::SingleExecution) {
            return;
        }

        if (! in_array($queryRequest->status, [QueryRequestStatus::Approved, QueryRequestStatus::Running], true)) {
            return;
        }

        $startedAt = now();
        $executorUser = $queryRequest->dispatchedBy ?? $queryRequest->requester;

        $queryRequest->forceFill([
            'status' => QueryRequestStatus::Running,
            'last_error' => null,
        ])->save();

        $execution = QueryExecution::query()->create([
            'query_request_id' => $queryRequest->id,
            'executed_by_id' => $executorUser->id,
            'sql' => $queryRequest->sql,
            'query_type' => $queryRequest->query_type,
            'status' => ExecutionStatus::Running,
            'started_at' => $startedAt,
        ]);

        try {
            $result = $executor->execute($queryRequest->databaseConnection, $queryRequest->sql, $queryRequest->query_type);
            $finishedAt = now();

            $execution->forceFill([
                'status' => ExecutionStatus::Succeeded,
                'finished_at' => $finishedAt,
                'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
                'row_count' => $result['row_count'],
                'result_truncated' => $result['result_truncated'] ?? false,
                'sample_rows' => $result['sample_rows'],
            ])->save();

            $queryRequest->forceFill([
                'status' => QueryRequestStatus::Completed,
                'completed_at' => $finishedAt,
                'result_summary' => [
                    'row_count' => $result['row_count'],
                    'result_truncated' => $result['result_truncated'] ?? false,
                    'sample_rows' => $result['sample_rows'],
                ],
            ])->save();

            $auditLogger->log('query_request.executed', $executorUser, $queryRequest, [
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

            $queryRequest->forceFill([
                'status' => QueryRequestStatus::Failed,
                'completed_at' => $finishedAt,
                'last_error' => $exception->getMessage(),
            ])->save();

            $auditLogger->log('query_request.execution_failed', $executorUser, $queryRequest, [
                'execution_id' => $execution->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
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
        }
    }
}
