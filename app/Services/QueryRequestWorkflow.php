<?php

namespace App\Services;

use App\Enums\AccessMode;
use App\Enums\ExecutionStatus;
use App\Enums\PreflightStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Jobs\ExecuteQueryRequest;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\QueryReview;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class QueryRequestWorkflow
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DeploymentPreflight $deploymentPreflight,
        private readonly QueryGuard $queryGuard,
        private readonly NotificationDispatcher $notificationDispatcher,
    ) {}

    /**
     * @param  DatabaseConnection|array{database_connection_id?:int,database_connection_ids?:array<int, int>,request_kind:string,title:string,description?:string|null,sql?:string|null,statements?:array<int, array{sql:string,database_connection_id:int}>,scheduled_at?:string|null,access_duration_minutes?:int|null}  $databaseConnectionOrData
     * @param  array{database_connection_id?:int,database_connection_ids?:array<int, int>,request_kind:string,title:string,description?:string|null,sql?:string|null,statements?:array<int, array{sql:string,database_connection_id:int}>,scheduled_at?:string|null,access_duration_minutes?:int|null}|null  $data
     *
     * @throws ValidationException
     */
    public function create(User $requester, DatabaseConnection|array $databaseConnectionOrData, ?array $data = null): QueryRequest
    {
        if ($databaseConnectionOrData instanceof DatabaseConnection) {
            if ($data === null) {
                throw new InvalidArgumentException('Query request data is required when creating a request for a database connection.');
            }

            $data['database_connection_id'] ??= $databaseConnectionOrData->id;
            $data['database_connection_ids'] ??= [$databaseConnectionOrData->id];
        } else {
            $data = $databaseConnectionOrData;
        }

        $requestKind = QueryRequestKind::from($data['request_kind']);
        $statements = $requestKind === QueryRequestKind::SingleExecution
            ? $this->validateStatements($this->statementInput($data))
            : [];
        $databaseConnections = $requestKind === QueryRequestKind::SingleExecution
            ? $this->databaseConnectionsForStatements($statements)
            : $this->databaseConnectionsForAccess($data);
        $databaseConnection = $requestKind === QueryRequestKind::SingleExecution
            ? $databaseConnections->get($statements[0]['database_connection_id'])
            : $databaseConnections->first();
        $requestedAccessMode = $requestKind === QueryRequestKind::QueryAccess
            ? $this->requestedAccessMode($data)
            : null;

        if (! $databaseConnection instanceof DatabaseConnection) {
            throw ValidationException::withMessages([
                'database_connection_id' => 'Select a valid database connection.',
            ]);
        }

        $queryType = $this->batchQueryType($statements);

        if (! $requester->isAdmin()) {
            if ($requestKind === QueryRequestKind::SingleExecution) {
                foreach ($statements as $index => $statement) {
                    $permission = $requester->effectiveDatabasePermissionFor(
                        $databaseConnections->get($statement['database_connection_id']),
                        $statement['query_type'],
                    );

                    if (! $permission['access_mode']->allows($statement['query_type'])) {
                        throw ValidationException::withMessages([
                            "statements.{$index}.database_connection_id" => 'Your role is not allowed to run this query type on the selected database.',
                        ]);
                    }
                }
            }

            if ($requestKind === QueryRequestKind::QueryAccess) {
                foreach ($databaseConnections as $connection) {
                    if (! $requestedAccessMode instanceof AccessMode
                        || ! $requester->effectiveDatabasePermissionFor(
                            $connection,
                            $this->queryTypeForAccessMode($requestedAccessMode),
                        )['access_mode']->allows($this->queryTypeForAccessMode($requestedAccessMode))) {
                        throw ValidationException::withMessages([
                            'database_connection_ids' => 'Your role is not allowed to request the selected session access level for every selected database.',
                        ]);
                    }
                }
            }
        }

        $requiresApproval = $this->requiresApproval(
            $requester,
            $databaseConnections,
            $statements,
            $requestedAccessMode,
        );
        $scheduledAt = $requestKind === QueryRequestKind::SingleExecution && filled($data['scheduled_at'] ?? null)
            ? Carbon::parse($data['scheduled_at'])
            : null;
        $accessDurationMinutes = $requestKind === QueryRequestKind::QueryAccess
            ? (int) ($data['access_duration_minutes'] ?? 60)
            : null;

        if ($requestedAccessMode === AccessMode::Write) {
            $this->ensureWriteSessionDurationIsAllowed($requester, $databaseConnections, $accessDurationMinutes ?? 60);
        }

        return DB::transaction(function () use ($requester, $databaseConnection, $databaseConnections, $data, $requestKind, $queryType, $statements, $requestedAccessMode, $requiresApproval, $scheduledAt, $accessDurationMinutes): QueryRequest {
            $status = QueryRequestStatus::PendingReview;

            if (! $requiresApproval) {
                $status = $scheduledAt?->isFuture() ? QueryRequestStatus::Scheduled : QueryRequestStatus::Approved;
            }

            $queryRequest = QueryRequest::query()->create([
                'requester_id' => $requester->id,
                'database_connection_id' => $databaseConnection->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'sql' => $statements[0]['sql'] ?? '',
                'query_type' => $queryType,
                'request_kind' => $requestKind,
                'requested_access_mode' => $requestedAccessMode,
                'status' => $status,
                'requires_approval' => $requiresApproval,
                'scheduled_at' => $scheduledAt,
                'access_duration_minutes' => $accessDurationMinutes,
                'approved_by_id' => $requiresApproval ? null : $requester->id,
                'approved_at' => $requiresApproval ? null : now(),
            ]);

            $this->replaceStatements($queryRequest, $statements);
            $queryRequest->accessConnections()->sync(
                $requestKind === QueryRequestKind::QueryAccess
                    ? $databaseConnections->pluck('id')->all()
                    : [],
            );

            if ($requestKind === QueryRequestKind::SingleExecution) {
                $this->refreshDeploymentPreflight($queryRequest);
            }

            $this->auditLogger->log('query_request.created', $requester, $queryRequest, [
                'status' => $queryRequest->status->value,
                'query_type' => $queryRequest->query_type->value,
                'request_kind' => $queryRequest->request_kind->value,
                'requested_access_mode' => $queryRequest->requested_access_mode?->value,
                'database_connection_ids' => $databaseConnections->pluck('id')->values()->all(),
                'statement_count' => count($statements),
            ]);

            if ($requiresApproval) {
                $this->notificationDispatcher->requestSubmitted($queryRequest);
            }

            return $queryRequest;
        });
    }

    /**
     * @param  array{database_connection_id?:int,database_connection_ids?:array<int, int>,request_kind:string,title:string,description?:string|null,sql?:string|null,statements?:array<int, array{sql:string,database_connection_id:int}>,scheduled_at?:string|null,access_duration_minutes?:int|null}  $data
     *
     * @throws ValidationException
     */
    public function update(QueryRequest $queryRequest, User $actor, array $data): QueryRequest
    {
        $requestKind = QueryRequestKind::from($data['request_kind']);
        $statements = $requestKind === QueryRequestKind::SingleExecution
            ? $this->validateStatements($this->statementInput($data))
            : [];
        $databaseConnections = $requestKind === QueryRequestKind::SingleExecution
            ? $this->databaseConnectionsForStatements($statements)
            : $this->databaseConnectionsForAccess($data);
        $databaseConnection = $requestKind === QueryRequestKind::SingleExecution
            ? $databaseConnections->get($statements[0]['database_connection_id'])
            : $databaseConnections->first();
        $requestedAccessMode = $requestKind === QueryRequestKind::QueryAccess
            ? $this->requestedAccessMode($data)
            : null;

        if (! $databaseConnection instanceof DatabaseConnection) {
            throw ValidationException::withMessages([
                'database_connection_id' => 'Select a valid database connection.',
            ]);
        }

        $queryType = $this->batchQueryType($statements);

        if (! $actor->isAdmin()) {
            if ($requestKind === QueryRequestKind::SingleExecution) {
                foreach ($statements as $index => $statement) {
                    $permission = $actor->effectiveDatabasePermissionFor(
                        $databaseConnections->get($statement['database_connection_id']),
                        $statement['query_type'],
                    );

                    if (! $permission['access_mode']->allows($statement['query_type'])) {
                        throw ValidationException::withMessages([
                            "statements.{$index}.database_connection_id" => 'Your role is not allowed to run this query type on the selected database.',
                        ]);
                    }
                }
            }

            if ($requestKind === QueryRequestKind::QueryAccess) {
                foreach ($databaseConnections as $connection) {
                    if (! $requestedAccessMode instanceof AccessMode
                        || ! $actor->effectiveDatabasePermissionFor(
                            $connection,
                            $this->queryTypeForAccessMode($requestedAccessMode),
                        )['access_mode']->allows($this->queryTypeForAccessMode($requestedAccessMode))) {
                        throw ValidationException::withMessages([
                            'database_connection_ids' => 'Your role is not allowed to request the selected session access level for every selected database.',
                        ]);
                    }
                }
            }
        }

        $scheduledAt = $requestKind === QueryRequestKind::SingleExecution && filled($data['scheduled_at'] ?? null)
            ? Carbon::parse($data['scheduled_at'])
            : null;
        $accessDurationMinutes = $requestKind === QueryRequestKind::QueryAccess
            ? (int) ($data['access_duration_minutes'] ?? 60)
            : null;

        if ($requestedAccessMode === AccessMode::Write) {
            $this->ensureWriteSessionDurationIsAllowed($actor, $databaseConnections, $accessDurationMinutes ?? 60);
        }

        return DB::transaction(function () use ($queryRequest, $actor, $databaseConnection, $databaseConnections, $data, $requestKind, $statements, $queryType, $requestedAccessMode, $scheduledAt, $accessDurationMinutes): QueryRequest {
            $lockedQueryRequest = QueryRequest::query()->lockForUpdate()->findOrFail($queryRequest->id);

            if (! $lockedQueryRequest->isEditable()) {
                throw ValidationException::withMessages([
                    'query_request' => 'This query request can no longer be edited.',
                ]);
            }

            $previousStatus = $lockedQueryRequest->status;

            $lockedQueryRequest->forceFill([
                'database_connection_id' => $databaseConnection->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'sql' => $statements[0]['sql'] ?? '',
                'query_type' => $queryType,
                'request_kind' => $requestKind,
                'requested_access_mode' => $requestedAccessMode,
                'status' => QueryRequestStatus::PendingReview,
                'requires_approval' => true,
                'scheduled_at' => $scheduledAt,
                'access_duration_minutes' => $accessDurationMinutes,
                'approved_by_id' => null,
                'approved_at' => null,
                'dispatched_by_id' => null,
                'dispatched_at' => null,
                'completed_at' => null,
                'result_summary' => null,
                'last_error' => null,
            ])->save();

            $this->replaceStatements($lockedQueryRequest, $statements);
            $lockedQueryRequest->accessConnections()->sync(
                $requestKind === QueryRequestKind::QueryAccess
                    ? $databaseConnections->pluck('id')->all()
                    : [],
            );

            if ($requestKind === QueryRequestKind::SingleExecution) {
                $this->refreshDeploymentPreflight($lockedQueryRequest);
            }

            $this->auditLogger->log('query_request.updated', $actor, $lockedQueryRequest, [
                'previous_status' => $previousStatus->value,
                'approval_invalidated' => in_array($previousStatus, [QueryRequestStatus::Approved, QueryRequestStatus::Scheduled], true),
                'status' => $lockedQueryRequest->status->value,
                'query_type' => $lockedQueryRequest->query_type->value,
                'request_kind' => $lockedQueryRequest->request_kind->value,
                'requested_access_mode' => $lockedQueryRequest->requested_access_mode?->value,
                'database_connection_ids' => $databaseConnections->pluck('id')->values()->all(),
                'statement_count' => count($statements),
            ]);

            $this->notificationDispatcher->reapprovalRequired($lockedQueryRequest);

            return $lockedQueryRequest->refresh();
        });
    }

    /**
     * @throws ValidationException
     */
    public function review(QueryRequest $queryRequest, User $reviewer, string $decision, ?string $comment = null): QueryReview
    {
        if ($queryRequest->status !== QueryRequestStatus::PendingReview) {
            throw ValidationException::withMessages([
                'decision' => 'Only pending requests can be reviewed.',
            ]);
        }

        return DB::transaction(function () use ($queryRequest, $reviewer, $decision, $comment): QueryReview {
            if ($decision === 'approved' && $queryRequest->request_kind === QueryRequestKind::SingleExecution) {
                $report = $this->refreshDeploymentPreflight($queryRequest);

                if ($report['status'] === PreflightStatus::Blocked) {
                    throw ValidationException::withMessages([
                        'decision' => 'This deployment batch is blocked by preflight checks. Resolve the blocked statements before approving it.',
                    ]);
                }
            }

            $review = QueryReview::query()->create([
                'query_request_id' => $queryRequest->id,
                'reviewer_id' => $reviewer->id,
                'decision' => $decision,
                'comment' => $comment,
            ]);

            if ($decision === 'approved') {
                $queryRequest->forceFill([
                    'status' => $queryRequest->scheduled_at?->isFuture() ? QueryRequestStatus::Scheduled : QueryRequestStatus::Approved,
                    'approved_by_id' => $reviewer->id,
                    'approved_at' => now(),
                ])->save();
            } else {
                $queryRequest->forceFill([
                    'status' => QueryRequestStatus::Rejected,
                    'completed_at' => now(),
                ])->save();
            }

            $this->auditLogger->log('query_request.reviewed', $reviewer, $queryRequest, [
                'decision' => $decision,
                'review_id' => $review->id,
            ]);

            $this->notificationDispatcher->requestReviewed($queryRequest, $decision);

            return $review;
        });
    }

    public function dispatch(QueryRequest $queryRequest, ?User $actor = null): bool
    {
        [$wasDispatched, $blockedScheduledRequest] = DB::transaction(function () use ($queryRequest, $actor): array {
            $lockedQueryRequest = QueryRequest::query()
                ->lockForUpdate()
                ->findOrFail($queryRequest->id);

            if ($lockedQueryRequest->request_kind !== QueryRequestKind::SingleExecution) {
                throw ValidationException::withMessages([
                    'query_request' => 'Only deployment batches can be dispatched.',
                ]);
            }

            if ($lockedQueryRequest->status === QueryRequestStatus::Scheduled && $lockedQueryRequest->scheduled_at?->isFuture()) {
                throw ValidationException::withMessages([
                    'query_request' => 'This deployment batch is scheduled for a future execution time.',
                ]);
            }

            if ($lockedQueryRequest->dispatched_at !== null || ! in_array($lockedQueryRequest->status, [QueryRequestStatus::Approved, QueryRequestStatus::Scheduled], true)) {
                return [false, null];
            }

            $wasScheduled = $lockedQueryRequest->status === QueryRequestStatus::Scheduled;
            $report = $this->refreshDeploymentPreflight($lockedQueryRequest);

            if ($report['status'] === PreflightStatus::Blocked) {
                $lockedQueryRequest->forceFill([
                    'status' => QueryRequestStatus::Approved,
                    'dispatched_at' => null,
                    'dispatched_by_id' => null,
                ])->save();

                $this->auditLogger->log('query_request.preflight_blocked', $actor, $lockedQueryRequest, [
                    'trigger' => $wasScheduled ? 'scheduled_dispatch' : 'manual_dispatch',
                    'blocker_count' => $report['summary']['blocker_count'],
                ]);

                return [false, $wasScheduled ? $lockedQueryRequest->fresh() : null];
            }

            $lockedQueryRequest->forceFill([
                'status' => QueryRequestStatus::Approved,
                'dispatched_at' => now(),
                'dispatched_by_id' => $actor?->id,
            ])->save();

            ExecuteQueryRequest::dispatch($lockedQueryRequest->id)->onQueue('queries')->afterCommit();

            $this->auditLogger->log('query_request.dispatched', $actor, $lockedQueryRequest);

            return [true, null];
        }, attempts: 3);

        if ($blockedScheduledRequest instanceof QueryRequest) {
            $this->notificationDispatcher->scheduledBatchPreflightBlocked($blockedScheduledRequest);
        }

        return $wasDispatched;
    }

    /**
     * @throws ValidationException
     */
    public function cancel(QueryRequest $queryRequest, User $actor, string $reason): QueryRequest
    {
        $cancelledRequest = DB::transaction(function () use ($queryRequest, $actor, $reason): QueryRequest {
            $lockedQueryRequest = QueryRequest::query()
                ->lockForUpdate()
                ->findOrFail($queryRequest->id);

            $canCancel = in_array($lockedQueryRequest->status, [
                QueryRequestStatus::PendingReview,
                QueryRequestStatus::Approved,
                QueryRequestStatus::Scheduled,
            ], true) || $lockedQueryRequest->status === QueryRequestStatus::Running;

            if (! $canCancel) {
                throw ValidationException::withMessages([
                    'query_request' => 'This query request can no longer be cancelled.',
                ]);
            }

            $wasRunning = $lockedQueryRequest->status === QueryRequestStatus::Running;
            $cancelledAt = now();
            $endedSessionCount = 0;

            if ($lockedQueryRequest->request_kind === QueryRequestKind::QueryAccess) {
                $endedSessionCount = $lockedQueryRequest->sessions()
                    ->whereNull('ended_at')
                    ->where('expires_at', '>', $cancelledAt)
                    ->update(['ended_at' => $cancelledAt]);
            }

            $lockedQueryRequest->forceFill([
                'status' => QueryRequestStatus::Cancelled,
                'cancelled_by_id' => $actor->id,
                'cancelled_at' => $cancelledAt,
                'cancellation_reason' => $reason,
                'completed_at' => $cancelledAt,
            ])->save();

            $this->auditLogger->log('query_request.cancelled', $actor, $lockedQueryRequest, [
                'reason' => $reason,
                'was_running' => $wasRunning,
                'ended_session_count' => $endedSessionCount,
            ]);

            return $lockedQueryRequest->refresh();
        }, attempts: 3);

        $this->notificationDispatcher->requestCancelled($cancelledRequest, $actor);

        return $cancelledRequest;
    }

    /**
     * Resume a failed read-only batch or create a linked reapproval request for state-changing work.
     *
     * @throws ValidationException
     */
    public function retry(QueryRequest $queryRequest, User $actor): QueryRequest
    {
        $retriedRequest = DB::transaction(function () use ($queryRequest, $actor): QueryRequest {
            $lockedQueryRequest = QueryRequest::query()
                ->with([
                    'requester',
                    'accessConnections',
                    'notificationSubscriptions',
                    'statements.databaseConnection',
                ])
                ->lockForUpdate()
                ->findOrFail($queryRequest->id);

            if ($lockedQueryRequest->request_kind === QueryRequestKind::QueryAccess) {
                if (! in_array($lockedQueryRequest->status, [QueryRequestStatus::Completed, QueryRequestStatus::Cancelled], true)) {
                    throw ValidationException::withMessages([
                        'query_request' => 'Only completed or cancelled query-access requests can be requested again.',
                    ]);
                }

                return $this->createRetryRequest($lockedQueryRequest, $actor, null);
            }

            if ($lockedQueryRequest->status !== QueryRequestStatus::Failed) {
                throw ValidationException::withMessages([
                    'query_request' => 'Only failed deployment batches can be retried.',
                ]);
            }

            $retryFromPosition = $this->retryFromPosition($lockedQueryRequest);

            if ($lockedQueryRequest->query_type === QueryType::Read) {
                $lockedQueryRequest->forceFill([
                    'status' => QueryRequestStatus::Approved,
                    'dispatched_at' => now(),
                    'dispatched_by_id' => $actor->id,
                    'completed_at' => null,
                    'last_error' => null,
                    'result_summary' => null,
                ])->save();

                ExecuteQueryRequest::dispatch($lockedQueryRequest->id, $retryFromPosition)
                    ->onQueue('queries')
                    ->afterCommit();

                $this->auditLogger->log('query_request.retry_dispatched', $actor, $lockedQueryRequest, [
                    'retry_from_statement_position' => $retryFromPosition,
                    'approval_reused' => true,
                ]);

                return $lockedQueryRequest->refresh();
            }

            return $this->createRetryRequest($lockedQueryRequest, $actor, $retryFromPosition);
        }, attempts: 3);

        if ($retriedRequest->id !== $queryRequest->id && $retriedRequest->requires_approval) {
            $this->notificationDispatcher->reapprovalRequired($retriedRequest);
        }

        return $retriedRequest;
    }

    private function retryFromPosition(QueryRequest $queryRequest): int
    {
        $failedExecution = $queryRequest->executions()
            ->with('statement')
            ->where('status', ExecutionStatus::Failed->value)
            ->latest('started_at')
            ->latest('id')
            ->first();

        return $failedExecution?->statement?->position ?? 1;
    }

    private function createRetryRequest(QueryRequest $sourceRequest, User $actor, ?int $retryFromPosition): QueryRequest
    {
        $statements = $sourceRequest->request_kind === QueryRequestKind::SingleExecution
            ? $sourceRequest->statements
                ->filter(fn ($statement): bool => $statement->position >= ($retryFromPosition ?? 1))
                ->values()
            : collect();
        $usesLegacySql = $sourceRequest->request_kind === QueryRequestKind::SingleExecution
            && $statements->isEmpty()
            && filled($sourceRequest->sql);
        $databaseConnection = $sourceRequest->request_kind === QueryRequestKind::SingleExecution
            ? $statements->first()?->databaseConnection ?? $sourceRequest->databaseConnection
            : $sourceRequest->accessConnections->first() ?? $sourceRequest->databaseConnection;

        if (! $databaseConnection instanceof DatabaseConnection) {
            throw ValidationException::withMessages([
                'query_request' => 'The retry target is no longer available.',
            ]);
        }

        if ($sourceRequest->request_kind === QueryRequestKind::SingleExecution && $statements->isEmpty() && ! $usesLegacySql) {
            throw ValidationException::withMessages([
                'query_request' => 'There are no remaining statements to include in the retry request.',
            ]);
        }

        $retryLabel = $sourceRequest->request_kind === QueryRequestKind::QueryAccess
            ? 'Renew access'
            : 'Retry';
        $retryContext = $sourceRequest->request_kind === QueryRequestKind::QueryAccess
            ? "Renewed access based on request #{$sourceRequest->id}."
            : "Retry of request #{$sourceRequest->id}, beginning at statement {$retryFromPosition}.";
        $description = trim(implode("\n\n", array_filter([
            $sourceRequest->description,
            $retryContext,
        ])));
        $requestedAccessMode = $sourceRequest->request_kind === QueryRequestKind::QueryAccess
            ? $sourceRequest->requested_access_mode ?? AccessMode::Read
            : null;
        $requiresApproval = true;
        $status = QueryRequestStatus::PendingReview;
        $approvedById = null;
        $approvedAt = null;

        if ($sourceRequest->request_kind === QueryRequestKind::QueryAccess) {
            $requester = $sourceRequest->requester;
            $databaseConnections = $sourceRequest->accessConnections
                ->whenEmpty(fn (): Collection => collect([$databaseConnection]));

            $this->ensureCanRequestQueryAccess($requester, $databaseConnections, $requestedAccessMode);

            if ($requestedAccessMode === AccessMode::Write) {
                $this->ensureWriteSessionDurationIsAllowed(
                    $requester,
                    $databaseConnections,
                    $sourceRequest->access_duration_minutes ?? 60,
                );
            }

            $requiresApproval = $this->requiresApproval(
                $requester,
                $databaseConnections,
                [],
                $requestedAccessMode,
            );
            $status = $requiresApproval ? QueryRequestStatus::PendingReview : QueryRequestStatus::Approved;
            $approvedById = $requiresApproval ? null : $requester->id;
            $approvedAt = $requiresApproval ? null : now();
        }

        $retryRequest = QueryRequest::query()->create([
            'requester_id' => $sourceRequest->requester_id,
            'database_connection_id' => $databaseConnection->id,
            'retry_of_id' => $sourceRequest->id,
            'title' => Str::limit("{$retryLabel}: {$sourceRequest->title}", 255, ''),
            'description' => Str::limit($description, 5000, ''),
            'sql' => $statements->first()?->sql ?? $sourceRequest->sql,
            'query_type' => $sourceRequest->query_type,
            'request_kind' => $sourceRequest->request_kind,
            'requested_access_mode' => $requestedAccessMode,
            'status' => $status,
            'requires_approval' => $requiresApproval,
            'access_duration_minutes' => $sourceRequest->access_duration_minutes,
            'approved_by_id' => $approvedById,
            'approved_at' => $approvedAt,
        ]);

        if ($sourceRequest->request_kind === QueryRequestKind::SingleExecution) {
            $retryStatements = $usesLegacySql
                ? [[
                    'position' => 1,
                    'database_connection_id' => $sourceRequest->database_connection_id,
                    'sql' => $sourceRequest->sql,
                    'query_type' => $sourceRequest->query_type,
                ]]
                : $statements->values()->map(
                    fn ($statement, int $index): array => [
                        'position' => $index + 1,
                        'database_connection_id' => $statement->database_connection_id,
                        'sql' => $statement->sql,
                        'query_type' => $statement->query_type,
                    ],
                )->all();

            $retryRequest->statements()->createMany($retryStatements);
            $this->refreshDeploymentPreflight($retryRequest);
        } else {
            $retryRequest->accessConnections()->sync($sourceRequest->accessConnections->pluck('id')->all());
        }

        $retryRequest->notificationSubscriptions()->createMany(
            $sourceRequest->notificationSubscriptions
                ->pluck('user_id')
                ->unique()
                ->map(fn (int $userId): array => ['user_id' => $userId])
                ->all(),
        );

        $this->auditLogger->log('query_request.retry_created', $actor, $retryRequest, [
            'retry_of_id' => $sourceRequest->id,
            'retry_from_statement_position' => $retryFromPosition,
            'approval_required' => $requiresApproval,
        ]);
        $this->auditLogger->log('query_request.retry_request_created', $actor, $sourceRequest, [
            'retry_request_id' => $retryRequest->id,
            'retry_from_statement_position' => $retryFromPosition,
        ]);

        return $retryRequest;
    }

    /**
     * @param  Collection<int, DatabaseConnection>  $databaseConnections
     *
     * @throws ValidationException
     */
    private function ensureCanRequestQueryAccess(User $user, Collection $databaseConnections, AccessMode $requestedAccessMode): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $queryType = $this->queryTypeForAccessMode($requestedAccessMode);

        foreach ($databaseConnections as $connection) {
            if (! $user->effectiveDatabasePermissionFor($connection, $queryType)['access_mode']->allows($queryType)) {
                throw ValidationException::withMessages([
                    'database_connection_ids' => 'Your role is not allowed to request the selected session access level for every selected database.',
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function requestedAccessMode(array $data): AccessMode
    {
        $accessMode = AccessMode::tryFrom((string) ($data['requested_access_mode'] ?? AccessMode::Read->value));

        if (! in_array($accessMode, [AccessMode::Read, AccessMode::Write], true)) {
            throw ValidationException::withMessages([
                'requested_access_mode' => 'Choose read-only or read + write access for this session.',
            ]);
        }

        return $accessMode;
    }

    private function queryTypeForAccessMode(AccessMode $accessMode): QueryType
    {
        return $accessMode === AccessMode::Write ? QueryType::Write : QueryType::Read;
    }

    /**
     * @param  Collection<int, DatabaseConnection>  $databaseConnections
     * @param  array<int, array{position:int, sql:string, query_type:QueryType, database_connection_id:int}>  $statements
     */
    private function requiresApproval(User $user, Collection $databaseConnections, array $statements, ?AccessMode $requestedAccessMode): bool
    {
        if ($user->isAdmin()) {
            return false;
        }

        if ($requestedAccessMode instanceof AccessMode) {
            $queryType = $this->queryTypeForAccessMode($requestedAccessMode);

            return $databaseConnections->contains(function (DatabaseConnection $connection) use ($user, $queryType): bool {
                $permission = $user->effectiveDatabasePermissionFor($connection, $queryType);

                return $queryType === QueryType::Read
                    ? $permission['read_requires_approval']
                    : $permission['write_requires_approval'];
            });
        }

        return collect($statements)->contains(function (array $statement) use ($user, $databaseConnections): bool {
            $queryType = $statement['query_type'];
            $connection = $databaseConnections->get($statement['database_connection_id']);

            if (! $connection instanceof DatabaseConnection) {
                return true;
            }

            $permission = $user->effectiveDatabasePermissionFor($connection, $queryType);

            return $queryType === QueryType::Read
                ? $permission['read_requires_approval']
                : $permission['write_requires_approval'];
        });
    }

    /**
     * @param  Collection<int, DatabaseConnection>  $databaseConnections
     *
     * @throws ValidationException
     */
    private function ensureWriteSessionDurationIsAllowed(User $user, Collection $databaseConnections, int $durationMinutes): void
    {
        if ($user->isAdmin()) {
            return;
        }

        foreach ($databaseConnections as $connection) {
            $maximumDuration = $user->effectiveDatabasePermissionFor($connection, QueryType::Write)['max_write_session_minutes'];

            if ($maximumDuration !== null && $durationMinutes > $maximumDuration) {
                throw ValidationException::withMessages([
                    'access_duration_minutes' => "Write sessions on {$connection->name} are limited to {$maximumDuration} minutes.",
                ]);
            }
        }
    }

    /**
     * @param  array<int, array{sql?:string,database_connection_id?:int}>  $statements
     * @return array<int, array{position:int, sql:string, query_type:QueryType, database_connection_id:int}>
     *
     * @throws ValidationException
     */
    private function validateStatements(array $statements): array
    {
        if ($statements === []) {
            throw ValidationException::withMessages([
                'statements' => 'At least one SQL statement is required.',
            ]);
        }

        $validated = [];

        foreach (array_values($statements) as $index => $statement) {
            $databaseConnectionId = (int) ($statement['database_connection_id'] ?? 0);

            if ($databaseConnectionId < 1) {
                throw ValidationException::withMessages([
                    "statements.{$index}.database_connection_id" => 'Select a connection for this statement.',
                ]);
            }

            try {
                $sql = $this->queryGuard->validateExecutable((string) ($statement['sql'] ?? ''));
                $queryType = $this->queryGuard->classify($sql);
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->first() ?? 'The SQL statement is invalid.';

                throw ValidationException::withMessages([
                    "statements.{$index}.sql" => $message,
                ]);
            }

            $validated[] = [
                'position' => $index + 1,
                'database_connection_id' => $databaseConnectionId,
                'sql' => $sql,
                'query_type' => $queryType,
            ];
        }

        return $validated;
    }

    /**
     * @param  array<int, array{position:int, sql:string, query_type:QueryType, database_connection_id:int}>  $statements
     */
    private function batchQueryType(array $statements): QueryType
    {
        return collect($statements)->contains(
            fn (array $statement): bool => $statement['query_type'] === QueryType::Write,
        ) ? QueryType::Write : QueryType::Read;
    }

    /**
     * @param  array<int, array{position:int, sql:string, query_type:QueryType, database_connection_id:int}>  $statements
     */
    private function replaceStatements(QueryRequest $queryRequest, array $statements): void
    {
        $queryRequest->statements()->delete();

        $queryRequest->statements()->createMany(array_map(
            fn (array $statement): array => [
                'position' => $statement['position'],
                'database_connection_id' => $statement['database_connection_id'],
                'sql' => $statement['sql'],
                'query_type' => $statement['query_type'],
            ],
            $statements,
        ));
    }

    /**
     * @param  array{database_connection_id?:int,sql?:string|null,statements?:array<int, array{sql:string,database_connection_id?:int}>}  $data
     * @return array<int, array{sql:string,database_connection_id?:int}>
     */
    private function statementInput(array $data): array
    {
        if (array_key_exists('statements', $data)) {
            return array_map(
                fn (array $statement): array => [
                    ...$statement,
                    'database_connection_id' => $statement['database_connection_id'] ?? $data['database_connection_id'] ?? null,
                ],
                $data['statements'],
            );
        }

        return filled($data['sql'] ?? null)
            ? [[
                'sql' => (string) $data['sql'],
                'database_connection_id' => $data['database_connection_id'] ?? null,
            ]]
            : [];
    }

    /**
     * @param  array<int, array{position:int, sql:string, query_type:QueryType, database_connection_id:int}>  $statements
     * @return Collection<int, DatabaseConnection>
     *
     * @throws ValidationException
     */
    private function databaseConnectionsForStatements(array $statements): Collection
    {
        $connectionIds = collect($statements)
            ->pluck('database_connection_id')
            ->unique()
            ->values();
        $databaseConnections = DatabaseConnection::query()
            ->whereKey($connectionIds)
            ->get()
            ->keyBy('id');

        if ($databaseConnections->count() !== $connectionIds->count()) {
            throw ValidationException::withMessages([
                'statements' => 'One or more selected connections are no longer available.',
            ]);
        }

        return $databaseConnections;
    }

    /**
     * @param  array{database_connection_id?:int,database_connection_ids?:array<int, int>}  $data
     * @return Collection<int, DatabaseConnection>
     *
     * @throws ValidationException
     */
    private function databaseConnectionsForAccess(array $data): Collection
    {
        $connectionIds = collect($data['database_connection_ids'] ?? [$data['database_connection_id'] ?? null])
            ->filter(fn (mixed $connectionId): bool => (int) $connectionId > 0)
            ->map(fn (mixed $connectionId): int => (int) $connectionId)
            ->unique()
            ->values();
        $databaseConnections = DatabaseConnection::query()
            ->whereKey($connectionIds)
            ->get()
            ->keyBy('id');

        if ($connectionIds->isEmpty() || $databaseConnections->count() !== $connectionIds->count()) {
            throw ValidationException::withMessages([
                'database_connection_ids' => 'Select one or more valid database connections.',
            ]);
        }

        return $databaseConnections;
    }

    /**
     * @return array{status:PreflightStatus,checked_at:string,summary:array{blocker_count:int,warning_count:int},statements:array<int, array{position:int,connection_id:int|null,connection_name:string|null,query_type:string|null,status:'passed'|'warning'|'blocked',messages:array<int, array{level:'warning'|'blocked',code:string,message:string}>}>}
     */
    private function refreshDeploymentPreflight(QueryRequest $queryRequest): array
    {
        $report = $this->deploymentPreflight->evaluate($queryRequest);

        $this->deploymentPreflight->persist($queryRequest, $report);

        return $report;
    }
}
