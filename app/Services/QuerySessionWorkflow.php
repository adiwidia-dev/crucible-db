<?php

namespace App\Services;

use App\Enums\AccessMode;
use App\Enums\AccessTransport;
use App\Enums\ExecutionStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Jobs\ExecuteQuerySessionQuery;
use App\Models\DatabaseConnection;
use App\Models\QueryExecution;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\QuerySessionQuery;
use App\Models\User;
use App\Services\NativeProxy\LeaseWorkflow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class QuerySessionWorkflow
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly QueryGuard $queryGuard,
        private readonly DatabaseQueryExecutor $executor,
        private readonly QueryResultSnapshotStore $resultSnapshotStore,
        private readonly NotificationDispatcher $notificationDispatcher,
        private readonly LeaseWorkflow $nativeProxyLeaseWorkflow,
        private readonly ApplicationSettings $applicationSettings,
        private readonly EffectiveQueryRequestPolicy $effectiveQueryRequestPolicy,
        private readonly DatabaseQueryCanceller $queryCanceller,
    ) {}

    /**
     * @throws ValidationException
     */
    public function queue(QuerySession $querySession, User $user, string $sql, ?DatabaseConnection $databaseConnection = null): QuerySessionQuery
    {
        [$databaseConnection, $normalizedSql, $queryType] = $this->validatedExecution(
            $querySession,
            $user,
            $sql,
            $databaseConnection,
        );

        $sessionQuery = Cache::lock('query-session:execution:'.$querySession->id, 10)->block(5, function () use ($querySession, $user, $databaseConnection, $normalizedSql, $queryType): QuerySessionQuery {
            return DB::transaction(function () use ($querySession, $user, $databaseConnection, $normalizedSql, $queryType): QuerySessionQuery {
                $hasActiveQuery = QuerySessionQuery::query()
                    ->where('query_session_id', $querySession->id)
                    ->whereNull('native_proxy_connection_id')
                    ->whereIn('status', [
                        ExecutionStatus::Queued,
                        ExecutionStatus::Running,
                        ExecutionStatus::Cancelling,
                    ])
                    ->lockForUpdate()
                    ->exists();

                if ($hasActiveQuery) {
                    throw ValidationException::withMessages([
                        'sql' => 'Wait for the active query to finish or cancel it before running another query.',
                    ]);
                }

                $queuedAt = now();
                $sessionQuery = QuerySessionQuery::query()->create([
                    'query_session_id' => $querySession->id,
                    'database_connection_id' => $databaseConnection->id,
                    'user_id' => $user->id,
                    'sql' => $normalizedSql,
                    'query_type' => $queryType,
                    'status' => ExecutionStatus::Queued,
                    'execution_token' => (string) Str::uuid(),
                    'timeout_seconds' => $this->applicationSettings->queryAccessTimeoutSeconds(),
                    'started_at' => $queuedAt,
                ]);

                QueryExecution::query()->create([
                    'query_request_id' => $querySession->query_request_id,
                    'query_session_query_id' => $sessionQuery->id,
                    'database_connection_id' => $databaseConnection->id,
                    'executed_by_id' => $user->id,
                    'sql' => $normalizedSql,
                    'query_type' => $queryType,
                    'status' => ExecutionStatus::Queued,
                ]);

                $this->auditLogger->log('query_session.query_queued', $user, $sessionQuery, [
                    'query_session_id' => $querySession->id,
                    'query_request_id' => $querySession->query_request_id,
                    'database_connection_id' => $databaseConnection->id,
                    'query_type' => $queryType->value,
                    'timeout_seconds' => $sessionQuery->timeout_seconds,
                    'sql' => $normalizedSql,
                ]);

                return $sessionQuery;
            });
        });

        try {
            ExecuteQuerySessionQuery::dispatch($sessionQuery->id)
                ->onConnection((string) config('query_access.queue_connection'))
                ->onQueue((string) config('query_access.queue'));
        } catch (Throwable $exception) {
            $this->markDispatchFailed($sessionQuery->id);
            report($exception);

            throw ValidationException::withMessages([
                'sql' => 'The query could not be queued. Try again in a moment.',
            ]);
        }

        return $sessionQuery;
    }

    /**
     * @throws ValidationException
     */
    public function start(QueryRequest $queryRequest, User $user): QuerySession
    {
        $result = Cache::lock('query-request:session-start:'.$queryRequest->id, 10)->block(5, function () use ($queryRequest, $user): QuerySession|QueryRequest {
            return DB::transaction(function () use ($queryRequest, $user): QuerySession|QueryRequest {
                $lockedQueryRequest = QueryRequest::query()
                    ->with(['requester', 'accessConnections', 'databaseConnection'])
                    ->lockForUpdate()
                    ->findOrFail($queryRequest->id);

                if ($lockedQueryRequest->request_kind !== QueryRequestKind::QueryAccess) {
                    throw ValidationException::withMessages([
                        'query_request' => 'Only query access requests can start a session.',
                    ]);
                }

                if ($lockedQueryRequest->status !== QueryRequestStatus::Approved || QuerySession::query()->where('query_request_id', $lockedQueryRequest->id)->exists()) {
                    throw ValidationException::withMessages([
                        'query_request' => 'This query access request must be approved and unused before a session can start.',
                    ]);
                }

                if ($lockedQueryRequest->access_transport === AccessTransport::NativeProxy && ! $this->applicationSettings->nativeClientAccessEnabled()) {
                    throw ValidationException::withMessages([
                        'query_request' => 'Native Client Access is currently disabled by an administrator.',
                    ]);
                }

                if ($lockedQueryRequest->access_transport === AccessTransport::Browser && ! $this->applicationSettings->queryAccessEnabled()) {
                    throw ValidationException::withMessages([
                        'query_request' => 'Query Access is currently disabled by an administrator.',
                    ]);
                }

                $databaseConnections = $this->sessionConnections($lockedQueryRequest);
                $sessionAccessMode = $lockedQueryRequest->requested_access_mode ?? AccessMode::Read;
                $this->effectiveQueryRequestPolicy->ensureQueryAccessRequestCanStart($lockedQueryRequest);

                if ($this->effectiveQueryRequestPolicy->requestRequiresFreshApproval($lockedQueryRequest)) {
                    $lockedQueryRequest->forceFill([
                        'status' => QueryRequestStatus::PendingReview,
                        'requires_approval' => true,
                        'approved_by_id' => null,
                        'approved_at' => null,
                        'dispatched_at' => null,
                        'dispatched_by_id' => null,
                        'revision' => $lockedQueryRequest->revision + 1,
                    ])->save();

                    $this->auditLogger->log('query_request.approval_invalidated', $user, $lockedQueryRequest, [
                        'trigger' => 'session_start',
                    ]);

                    return $lockedQueryRequest->fresh();
                }

                $startedAt = now();
                $durationMinutes = $lockedQueryRequest->access_duration_minutes ?? 60;
                $session = QuerySession::query()->create([
                    'query_request_id' => $lockedQueryRequest->id,
                    'user_id' => $user->id,
                    'database_connection_id' => $databaseConnections->first()->id,
                    'started_at' => $startedAt,
                    'expires_at' => $startedAt->copy()->addMinutes($durationMinutes),
                ]);

                $session->databaseConnections()->sync($databaseConnections->modelKeys());

                $lockedQueryRequest->forceFill([
                    'status' => QueryRequestStatus::Running,
                    'dispatched_at' => $startedAt,
                ])->save();

                $this->auditLogger->log('query_session.started', $user, $session, [
                    'query_request_id' => $lockedQueryRequest->id,
                    'expires_at' => $session->expires_at->toIso8601String(),
                    'database_connection_ids' => $databaseConnections->modelKeys(),
                    'requested_access_mode' => $sessionAccessMode->value,
                ]);

                $this->notificationDispatcher->sessionStarted($session);

                return $session;
            });
        });

        if ($result instanceof QueryRequest) {
            $this->notificationDispatcher->reapprovalRequired($result);

            throw ValidationException::withMessages([
                'query_request' => 'The requester\'s current policy now requires approval. This request was returned to Pending Review.',
            ]);
        }

        return $result;
    }

    /**
     * @return array{query:QuerySessionQuery, result:array{row_count:int, sample_rows:array<int, array<string, mixed>>, result_rows:array<int, array<string, mixed>>, result_byte_count:int, result_truncated?:bool}|null}
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

        $querySession->loadMissing('databaseConnection', 'databaseConnections', 'queryRequest');
        $databaseConnection ??= $querySession->databaseConnection;

        if (! $querySession->databaseConnections->contains('id', $databaseConnection->id)) {
            throw ValidationException::withMessages([
                'database_connection_id' => 'Select a connection approved for this session.',
            ]);
        }

        $normalizedSql = $this->queryGuard->validateSessionExecutable($sql);
        $queryType = $this->queryGuard->classify($normalizedSql);
        $sessionAccessMode = $querySession->queryRequest->requested_access_mode ?? AccessMode::Read;

        if (! $sessionAccessMode->allows($queryType)) {
            throw ValidationException::withMessages([
                'sql' => 'This is a read-only session. Request read + write access before running data-changing SQL.',
            ]);
        }

        $permission = $user->effectiveQueryAccessPermissionFor($databaseConnection, $queryType);

        if (! $user->isAdmin() && ! $permission['query_access_mode']->allows($queryType)) {
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

            if ($queryType === QueryType::Read) {
                $resultRows = $result['result_rows'];
                $resultByteCount = $result['result_byte_count'];

                $this->resultSnapshotStore->store(
                    $sessionQuery,
                    $resultRows,
                    $resultByteCount,
                    $querySession->expires_at,
                );
            }

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

    public function executeQueued(int $querySessionQueryId): void
    {
        $sessionQuery = DB::transaction(function () use ($querySessionQueryId): ?QuerySessionQuery {
            $query = QuerySessionQuery::query()
                ->with(['querySession.queryRequest', 'user', 'databaseConnection'])
                ->lockForUpdate()
                ->findOrFail($querySessionQueryId);

            if ($query->status !== ExecutionStatus::Queued) {
                return null;
            }

            $query->forceFill([
                'status' => ExecutionStatus::Running,
                'started_at' => now(),
            ])->save();
            $this->updateLinkedExecution($query, [
                'status' => ExecutionStatus::Running,
                'started_at' => $query->started_at,
            ]);

            return $query;
        });

        if ($sessionQuery === null) {
            return;
        }

        $executionStartedAt = null;
        $executionDurationMilliseconds = 0;

        try {
            [$databaseConnection, $normalizedSql, $queryType] = $this->validatedExecution(
                $sessionQuery->querySession,
                $sessionQuery->user,
                $sessionQuery->sql,
                $sessionQuery->databaseConnection,
            );
            $executionTag = $sessionQuery->id.':'.$sessionQuery->execution_token;
            $executionStartedAt = (int) hrtime(true);
            $result = $this->executor->executeTracked(
                $databaseConnection,
                $normalizedSql,
                $queryType,
                $sessionQuery->timeout_seconds,
                function (string $backendProcessId, ?string $applicationName) use ($sessionQuery): void {
                    $sessionQuery->refresh();
                    $sessionQuery->forceFill([
                        'backend_process_id' => $backendProcessId,
                        'backend_application_name' => $applicationName,
                    ])->save();

                    if ($sessionQuery->cancel_requested_at !== null || $sessionQuery->status === ExecutionStatus::Cancelling) {
                        throw new \RuntimeException('Query cancellation was requested before execution began.');
                    }
                },
                $executionTag,
            );
            $executionDurationMilliseconds = $this->elapsedMilliseconds($executionStartedAt);

            $sessionQuery->refresh();

            if ($sessionQuery->status->isTerminal()) {
                return;
            }

            if ($sessionQuery->cancel_requested_at !== null || $sessionQuery->status === ExecutionStatus::Cancelling) {
                $this->finishQueuedExecution(
                    $sessionQuery,
                    ExecutionStatus::Cancelled,
                    'Query cancelled by user.',
                    $executionDurationMilliseconds,
                );

                return;
            }

            $finishedAt = now();
            $sessionQuery->forceFill([
                'status' => ExecutionStatus::Succeeded,
                'finished_at' => $finishedAt,
                'duration_ms' => $executionDurationMilliseconds,
                'row_count' => $result['row_count'],
                'result_truncated' => $result['result_truncated'] ?? false,
                'sample_rows' => $result['sample_rows'],
            ])->save();

            if ($queryType === QueryType::Read) {
                $this->resultSnapshotStore->store(
                    $sessionQuery,
                    $result['result_rows'],
                    $result['result_byte_count'],
                    $sessionQuery->querySession->expires_at,
                );
            }

            $this->updateLinkedExecution($sessionQuery, [
                'status' => ExecutionStatus::Succeeded,
                'finished_at' => $finishedAt,
                'duration_ms' => $executionDurationMilliseconds,
                'row_count' => $result['row_count'],
                'result_truncated' => $result['result_truncated'] ?? false,
                'sample_rows' => $result['sample_rows'],
            ]);
            $this->auditLogger->log('query_session.query_executed', $sessionQuery->user, $sessionQuery, [
                'query_session_id' => $sessionQuery->query_session_id,
                'query_request_id' => $sessionQuery->querySession->query_request_id,
                'database_connection_id' => $databaseConnection->id,
                'query_type' => $queryType->value,
                'row_count' => $result['row_count'],
                'result_truncated' => $result['result_truncated'] ?? false,
                'sql' => $normalizedSql,
            ]);
        } catch (Throwable $exception) {
            $sessionQuery->refresh();

            if ($sessionQuery->status->isTerminal()) {
                return;
            }

            $status = $sessionQuery->cancel_requested_at !== null || $sessionQuery->status === ExecutionStatus::Cancelling
                ? ExecutionStatus::Cancelled
                : ($this->isStatementTimeout($exception) ? ExecutionStatus::TimedOut : ExecutionStatus::Failed);
            $message = match ($status) {
                ExecutionStatus::Cancelled => 'Query cancelled by user.',
                ExecutionStatus::TimedOut => 'Query exceeded its '.$sessionQuery->timeout_seconds.' second time limit.',
                default => $exception->getMessage(),
            };

            $this->finishQueuedExecution(
                $sessionQuery,
                $status,
                $message,
                $executionStartedAt === null
                    ? 0
                    : $this->elapsedMilliseconds($executionStartedAt),
            );
        }
    }

    public function cancel(QuerySessionQuery $sessionQuery, ?User $user): QuerySessionQuery
    {
        $shouldCancelBackend = DB::transaction(function () use ($sessionQuery, $user): bool {
            $lockedQuery = QuerySessionQuery::query()->lockForUpdate()->findOrFail($sessionQuery->id);

            if ($lockedQuery->status->isTerminal()) {
                return false;
            }

            $now = now();

            if ($lockedQuery->status === ExecutionStatus::Queued) {
                $lockedQuery->forceFill([
                    'status' => ExecutionStatus::Cancelled,
                    'cancel_requested_at' => $now,
                    'finished_at' => $now,
                    'duration_ms' => 0,
                    'error_message' => 'Query cancelled before execution began.',
                ])->save();
                $this->updateLinkedExecution($lockedQuery, [
                    'status' => ExecutionStatus::Cancelled,
                    'finished_at' => $now,
                    'duration_ms' => 0,
                    'error_message' => 'Query cancelled before execution began.',
                ]);
            } else {
                $lockedQuery->forceFill([
                    'status' => ExecutionStatus::Cancelling,
                    'cancel_requested_at' => $now,
                ])->save();
                $this->updateLinkedExecution($lockedQuery, [
                    'status' => ExecutionStatus::Cancelling,
                ]);
            }

            $this->auditLogger->log('query_session.query_cancel_requested', $user, $lockedQuery, [
                'query_session_id' => $lockedQuery->query_session_id,
            ]);

            return $lockedQuery->status === ExecutionStatus::Cancelling;
        });

        $sessionQuery->refresh();

        if ($shouldCancelBackend) {
            try {
                $this->queryCanceller->cancel($sessionQuery);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $sessionQuery->refresh();
    }

    public function cancelForSession(QuerySession $querySession, ?User $user): void
    {
        $querySession->queries()
            ->whereNull('native_proxy_connection_id')
            ->whereIn('status', [ExecutionStatus::Queued, ExecutionStatus::Running, ExecutionStatus::Cancelling])
            ->get()
            ->each(fn (QuerySessionQuery $query): QuerySessionQuery => $this->cancel($query, $user));
    }

    public function markInterrupted(int $querySessionQueryId, Throwable $exception, bool $reconciledTimeout = false): void
    {
        $sessionQuery = QuerySessionQuery::query()->find($querySessionQueryId);

        if ($sessionQuery === null || $sessionQuery->status->isTerminal()) {
            return;
        }

        try {
            $this->queryCanceller->cancel($sessionQuery);
        } catch (Throwable $cancellationException) {
            report($cancellationException);
        }

        $status = match (true) {
            $sessionQuery->cancel_requested_at !== null => ExecutionStatus::Cancelled,
            $reconciledTimeout,
            $exception instanceof TimeoutExceededException,
            $this->isStatementTimeout($exception) => ExecutionStatus::TimedOut,
            default => ExecutionStatus::Failed,
        };
        $message = match ($status) {
            ExecutionStatus::Cancelled => 'Query cancelled by user.',
            ExecutionStatus::TimedOut => 'Query worker exceeded its execution time limit.',
            default => $exception->getMessage(),
        };

        $this->finishQueuedExecution($sessionQuery, $status, $message);
    }

    public function markDispatchFailed(int $querySessionQueryId): void
    {
        $sessionQuery = QuerySessionQuery::query()->find($querySessionQueryId);

        if ($sessionQuery === null || $sessionQuery->status !== ExecutionStatus::Queued) {
            return;
        }

        $this->finishQueuedExecution(
            $sessionQuery,
            ExecutionStatus::Failed,
            'Query could not be started by the execution worker.',
        );
    }

    public function end(QuerySession $querySession, User $user): void
    {
        if ($querySession->ended_at !== null) {
            return;
        }

        DB::transaction(function () use ($querySession, $user): void {
            $lockedQueryRequest = QueryRequest::query()
                ->lockForUpdate()
                ->findOrFail($querySession->query_request_id);

            $querySession->forceFill([
                'ended_at' => now(),
            ])->save();

            if ($lockedQueryRequest->status === QueryRequestStatus::Running) {
                $lockedQueryRequest->forceFill([
                    'status' => QueryRequestStatus::Completed,
                    'completed_at' => now(),
                ])->save();
            }

            $this->auditLogger->log('query_session.ended', $user, $querySession);
            $this->nativeProxyLeaseWorkflow->revokeForQuerySession(
                $querySession,
                $user,
                'Query access session ended.',
            );
        });

        $this->cancelForSession($querySession, $user);
    }

    /**
     * @return array{DatabaseConnection, string, QueryType}
     *
     * @throws ValidationException
     */
    private function validatedExecution(
        QuerySession $querySession,
        User $user,
        string $sql,
        ?DatabaseConnection $databaseConnection,
    ): array {
        if (! $querySession->isActive()) {
            throw ValidationException::withMessages([
                'sql' => 'This query session is not active.',
            ]);
        }

        $querySession->loadMissing('databaseConnection', 'databaseConnections', 'queryRequest');
        $databaseConnection ??= $querySession->databaseConnection;

        if (! $querySession->databaseConnections->contains('id', $databaseConnection->id)) {
            throw ValidationException::withMessages([
                'database_connection_id' => 'Select a connection approved for this session.',
            ]);
        }

        $normalizedSql = $this->queryGuard->validateSessionExecutable($sql);
        $queryType = $this->queryGuard->classify($normalizedSql);
        $sessionAccessMode = $querySession->queryRequest->requested_access_mode ?? AccessMode::Read;

        if (! $sessionAccessMode->allows($queryType)) {
            throw ValidationException::withMessages([
                'sql' => 'This is a read-only session. Request read + write access before running data-changing SQL.',
            ]);
        }

        $permission = $user->effectiveQueryAccessPermissionFor($databaseConnection, $queryType);

        if (! $user->isAdmin() && ! $permission['query_access_mode']->allows($queryType)) {
            throw ValidationException::withMessages([
                'sql' => 'Your current roles are not allowed to run this query type on the selected database.',
            ]);
        }

        return [$databaseConnection, $normalizedSql, $queryType];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateLinkedExecution(QuerySessionQuery $sessionQuery, array $attributes): void
    {
        QueryExecution::query()
            ->where('query_session_query_id', $sessionQuery->id)
            ->update([
                ...$attributes,
                'updated_at' => now(),
            ]);
    }

    private function finishQueuedExecution(
        QuerySessionQuery $sessionQuery,
        ExecutionStatus $status,
        string $message,
        ?int $durationMilliseconds = null,
    ): void {
        $finishedAt = now();
        $duration = $durationMilliseconds ?? ($sessionQuery->started_at === null
            ? 0
            : (int) $sessionQuery->started_at->diffInMilliseconds($finishedAt));

        $sessionQuery->forceFill([
            'status' => $status,
            'finished_at' => $finishedAt,
            'duration_ms' => $duration,
            'error_message' => $message,
        ])->save();
        $this->updateLinkedExecution($sessionQuery, [
            'status' => $status,
            'finished_at' => $finishedAt,
            'duration_ms' => $duration,
            'error_message' => $message,
        ]);
        $this->auditLogger->log('query_session.query_'.$status->value, $sessionQuery->user, $sessionQuery, [
            'query_session_id' => $sessionQuery->query_session_id,
            'query_request_id' => $sessionQuery->querySession->query_request_id,
            'database_connection_id' => $sessionQuery->database_connection_id,
            'query_type' => $sessionQuery->query_type->value,
            'error' => $message,
            'sql' => $sessionQuery->sql,
        ]);
    }

    private function elapsedMilliseconds(int $startedAtNanoseconds): int
    {
        return max(0, intdiv(hrtime(true) - $startedAtNanoseconds, 1_000_000));
    }

    private function isStatementTimeout(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof QueryException) {
                $sqlState = (string) ($current->errorInfo[0] ?? '');
                $driverCode = (int) ($current->errorInfo[1] ?? 0);

                if ($sqlState === '57014' || $driverCode === 3024) {
                    return true;
                }
            }

            $message = mb_strtolower($current->getMessage());

            if (str_contains($message, 'statement timeout') || str_contains($message, 'maximum statement execution time exceeded')) {
                return true;
            }
        }

        return false;
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
