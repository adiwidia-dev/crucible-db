<?php

namespace App\Services;

use App\Enums\ExecutionStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Models\DatabaseConnection;
use App\Models\QueryExecution;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\QuerySessionQuery;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class QuerySessionWorkflow
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly QueryGuard $queryGuard,
        private readonly DatabaseQueryExecutor $executor,
    ) {}

    /**
     * @throws ValidationException
     */
    public function start(QueryRequest $queryRequest, User $user): QuerySession
    {
        if ($queryRequest->request_kind !== QueryRequestKind::QueryAccess) {
            throw ValidationException::withMessages([
                'query_request' => 'Only query access requests can start a session.',
            ]);
        }

        if ($queryRequest->status !== QueryRequestStatus::Approved) {
            throw ValidationException::withMessages([
                'query_request' => 'This query access request must be approved before a session can start.',
            ]);
        }

        $queryRequest->loadMissing('accessConnections', 'databaseConnection');
        $databaseConnections = $this->sessionConnections($queryRequest);

        if (! $user->isAdmin() && $databaseConnections->contains(
            fn (DatabaseConnection $connection): bool => ! $user->canAccessDatabase($connection),
        )) {
            throw ValidationException::withMessages([
                'query_request' => 'You no longer have access to every database approved for this session.',
            ]);
        }

        $startedAt = now();
        $durationMinutes = $queryRequest->access_duration_minutes ?? 60;

        return DB::transaction(function () use ($queryRequest, $user, $databaseConnections, $startedAt, $durationMinutes): QuerySession {
            $session = QuerySession::query()->create([
                'query_request_id' => $queryRequest->id,
                'user_id' => $user->id,
                'database_connection_id' => $databaseConnections->first()->id,
                'started_at' => $startedAt,
                'expires_at' => $startedAt->copy()->addMinutes($durationMinutes),
            ]);

            $session->databaseConnections()->sync($databaseConnections->modelKeys());

            $queryRequest->forceFill([
                'status' => QueryRequestStatus::Running,
                'dispatched_at' => $startedAt,
            ])->save();

            $this->auditLogger->log('query_session.started', $user, $session, [
                'query_request_id' => $queryRequest->id,
                'expires_at' => $session->expires_at->toIso8601String(),
                'database_connection_ids' => $databaseConnections->modelKeys(),
            ]);

            return $session;
        });
    }

    /**
     * @return array{query:QuerySessionQuery, result:array{row_count:int, sample_rows:array<int, array<string, mixed>>, result_truncated?:bool}|null}
     *
     * @throws ValidationException
     */
    public function execute(QuerySession $querySession, User $user, string $sql, ?DatabaseConnection $databaseConnection = null): array
    {
        if (! $querySession->isActive()) {
            throw ValidationException::withMessages([
                'sql' => 'This query session is not active.',
            ]);
        }

        $querySession->loadMissing('databaseConnection', 'databaseConnections');
        $databaseConnection ??= $querySession->databaseConnection;

        if (! $querySession->databaseConnections->contains('id', $databaseConnection->id)) {
            throw ValidationException::withMessages([
                'database_connection_id' => 'Select a connection approved for this session.',
            ]);
        }

        $queryType = $this->queryGuard->classify($sql);
        $normalizedSql = $this->queryGuard->validateExecutable($sql);
        $permission = $user->effectiveDatabasePermission($databaseConnection);

        if (! $user->isAdmin() && ! $permission['access_mode']->allows($queryType)) {
            throw ValidationException::withMessages([
                'sql' => 'Your current roles are not allowed to run this query type on the selected database.',
            ]);
        }

        $startedAt = now();

        $sessionQuery = QuerySessionQuery::query()->create([
            'query_session_id' => $querySession->id,
            'database_connection_id' => $databaseConnection->id,
            'user_id' => $user->id,
            'sql' => $normalizedSql,
            'query_type' => $queryType,
            'status' => ExecutionStatus::Running,
            'started_at' => $startedAt,
        ]);

        try {
            $result = $this->executor->execute($databaseConnection, $normalizedSql, $queryType);
            $finishedAt = now();

            $sessionQuery->forceFill([
                'status' => ExecutionStatus::Succeeded,
                'finished_at' => $finishedAt,
                'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
                'row_count' => $result['row_count'],
                'result_truncated' => $result['result_truncated'] ?? false,
                'sample_rows' => $result['sample_rows'],
            ])->save();

            QueryExecution::query()->create([
                'query_request_id' => $querySession->query_request_id,
                'database_connection_id' => $databaseConnection->id,
                'executed_by_id' => $user->id,
                'sql' => $normalizedSql,
                'query_type' => $queryType,
                'status' => ExecutionStatus::Succeeded,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
                'row_count' => $result['row_count'],
                'result_truncated' => $result['result_truncated'] ?? false,
                'sample_rows' => $result['sample_rows'],
            ]);

            $this->auditLogger->log('query_session.query_executed', $user, $sessionQuery, [
                'query_session_id' => $querySession->id,
                'query_request_id' => $querySession->query_request_id,
                'database_connection_id' => $databaseConnection->id,
                'query_type' => $queryType->value,
                'row_count' => $result['row_count'],
                'result_truncated' => $result['result_truncated'] ?? false,
                'sql' => $normalizedSql,
            ]);

            return [
                'query' => $sessionQuery,
                'result' => $result,
            ];
        } catch (Throwable $exception) {
            $finishedAt = now();

            $sessionQuery->forceFill([
                'status' => ExecutionStatus::Failed,
                'finished_at' => $finishedAt,
                'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
                'error_message' => $exception->getMessage(),
            ])->save();

            QueryExecution::query()->create([
                'query_request_id' => $querySession->query_request_id,
                'database_connection_id' => $databaseConnection->id,
                'executed_by_id' => $user->id,
                'sql' => $normalizedSql,
                'query_type' => $queryType,
                'status' => ExecutionStatus::Failed,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
                'error_message' => $exception->getMessage(),
            ]);

            $this->auditLogger->log('query_session.query_failed', $user, $sessionQuery, [
                'query_session_id' => $querySession->id,
                'query_request_id' => $querySession->query_request_id,
                'database_connection_id' => $databaseConnection->id,
                'query_type' => $queryType->value,
                'error' => $exception->getMessage(),
                'sql' => $normalizedSql,
            ]);

            return [
                'query' => $sessionQuery,
                'result' => null,
            ];
        }
    }

    public function end(QuerySession $querySession, User $user): void
    {
        if ($querySession->ended_at !== null) {
            return;
        }

        DB::transaction(function () use ($querySession, $user): void {
            $querySession->forceFill([
                'ended_at' => now(),
            ])->save();

            $querySession->queryRequest->forceFill([
                'status' => QueryRequestStatus::Completed,
                'completed_at' => now(),
            ])->save();

            $this->auditLogger->log('query_session.ended', $user, $querySession);
        });
    }

    /**
     * @return Collection<int, DatabaseConnection>
     */
    private function sessionConnections(QueryRequest $queryRequest): Collection
    {
        return $queryRequest->accessConnections->isNotEmpty()
            ? $queryRequest->accessConnections
            : new Collection([$queryRequest->databaseConnection]);
    }
}
