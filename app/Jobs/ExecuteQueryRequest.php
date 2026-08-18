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

    public int $timeout = 240;

    public function __construct(public int $queryRequestId) {}

    public function handle(DatabaseQueryExecutor $executor, AuditLogger $auditLogger): void
    {
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

        $queryRequest->forceFill([
            'status' => QueryRequestStatus::Running,
            'last_error' => null,
        ])->save();

        $statements = $queryRequest->statements->map(fn ($statement): array => [
            'id' => $statement->id,
            'position' => $statement->position,
            'database_connection' => $statement->databaseConnection ?? $queryRequest->databaseConnection,
            'sql' => $statement->sql,
            'query_type' => $statement->query_type,
        ]);

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

                throw $exception;
            }
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
