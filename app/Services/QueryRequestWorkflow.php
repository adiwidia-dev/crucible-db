<?php

namespace App\Services;

use App\Enums\AccessMode;
use App\Enums\AccessTransport;
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
        $accessTransport = $this->accessTransport($data);
        $this->ensureAccessTransportIsValidForRequestKind($accessTransport, $requestKind);
        $statements = $requestKind === QueryRequestKind::SingleExecution
            ? $this->validateStatements($this->statementInput($data))
            : [];
        $usesEmergencySqlFallback = $requestKind === QueryRequestKind::SingleExecution
            && collect($statements)->contains(
                fn (array $statement): bool => $this->queryGuard->usesEmergencySqlFallback($statement['sql']),
            );
        $databaseConnections = $requestKind === QueryRequestKind::SingleExecution
            ? $this->databaseConnectionsForStatements($statements)
            : $this->databaseConnectionsForAccess($data, $accessTransport);
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
                if (! $requestedAccessMode instanceof AccessMode) {
                    throw ValidationException::withMessages([
                        'requested_access_mode' => 'Choose read-only or read + write access for this session.',
                    ]);
                }

                $this->ensureCanRequestAccess($requester, $databaseConnections, $requestedAccessMode, $accessTransport);
            }
        }

        $requiresApproval = $this->requiresApproval(
            $requester,
            $databaseConnections,
            $statements,
            $requestedAccessMode,
            $accessTransport,
        );
        $scheduledAt = $requestKind === QueryRequestKind::SingleExecution && filled($data['scheduled_at'] ?? null)
            ? Carbon::parse($data['scheduled_at'])
            : null;
        $accessDurationMinutes = $requestKind === QueryRequestKind::QueryAccess
            ? (int) ($data['access_duration_minutes'] ?? 60)
            : null;

        if ($requestedAccessMode === AccessMode::Write) {
            $this->ensureWriteSessionDurationIsAllowed($requester, $databaseConnections, $accessDurationMinutes ?? 60, $accessTransport);
        }

        return DB::transaction(function () use ($requester, $databaseConnection, $databaseConnections, $data, $requestKind, $accessTransport, $queryType, $statements, $usesEmergencySqlFallback, $requestedAccessMode, $requiresApproval, $scheduledAt, $accessDurationMinutes): QueryRequest {
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
                'access_transport' => $accessTransport,
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
                'uses_emergency_sql_fallback' => $usesEmergencySqlFallback,
            ]);

            if ($usesEmergencySqlFallback) {
                $this->auditLogger->log('query_request.emergency_sql_fallback_used', $requester, $queryRequest, [
                    'statement_positions' => collect($statements)
                        ->filter(fn (array $statement): bool => $this->queryGuard->usesEmergencySqlFallback($statement['sql']))
                        ->pluck('position')
                        ->values()
                        ->all(),
                ]);
            }

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
    public function createDraft(User $requester, array $data): QueryRequest
    {
        $this->ensureAccessTransportIsValidForRequestKind(
            $this->accessTransport($data),
            QueryRequestKind::from($data['request_kind']),
        );
        $this->ensureDeploymentBatch($data);

        $statements = $this->draftStatements($this->statementInput($data));
        $databaseConnections = $this->databaseConnectionsForStatements($statements);
        $databaseConnection = $databaseConnections->get($statements[0]['database_connection_id']);

        if (! $databaseConnection instanceof DatabaseConnection) {
            throw ValidationException::withMessages([
                'database_connection_id' => 'Select a valid database connection.',
            ]);
        }

        $scheduledAt = filled($data['scheduled_at'] ?? null)
            ? Carbon::parse($data['scheduled_at'])
            : null;

        return DB::transaction(function () use ($requester, $data, $statements, $databaseConnection, $scheduledAt): QueryRequest {
            $queryRequest = QueryRequest::query()->create([
                'requester_id' => $requester->id,
                'database_connection_id' => $databaseConnection->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'sql' => $statements[0]['sql'],
                'query_type' => QueryType::Write,
                'request_kind' => QueryRequestKind::SingleExecution,
                'status' => QueryRequestStatus::Draft,
                'requires_approval' => true,
                'scheduled_at' => $scheduledAt,
            ]);

            $this->replaceStatements($queryRequest, $statements);
            $this->refreshDeploymentPreflight($queryRequest);

            $this->auditLogger->log('query_request.draft_saved', $requester, $queryRequest, [
                'statement_count' => count($statements),
                'database_connection_ids' => collect($statements)
                    ->pluck('database_connection_id')
                    ->unique()
                    ->values()
                    ->all(),
            ]);

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
        $accessTransport = $this->accessTransport($data);
        $this->ensureAccessTransportIsValidForRequestKind($accessTransport, $requestKind);
        $statements = $requestKind === QueryRequestKind::SingleExecution
            ? $this->validateStatements($this->statementInput($data))
            : [];
        $usesEmergencySqlFallback = $requestKind === QueryRequestKind::SingleExecution
            && collect($statements)->contains(
                fn (array $statement): bool => $this->queryGuard->usesEmergencySqlFallback($statement['sql']),
            );
        $databaseConnections = $requestKind === QueryRequestKind::SingleExecution
            ? $this->databaseConnectionsForStatements($statements)
            : $this->databaseConnectionsForAccess($data, $accessTransport);
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
                if (! $requestedAccessMode instanceof AccessMode) {
                    throw ValidationException::withMessages([
                        'requested_access_mode' => 'Choose read-only or read + write access for this session.',
                    ]);
                }

                $this->ensureCanRequestAccess($actor, $databaseConnections, $requestedAccessMode, $accessTransport);
            }
        }

        $scheduledAt = $requestKind === QueryRequestKind::SingleExecution && filled($data['scheduled_at'] ?? null)
            ? Carbon::parse($data['scheduled_at'])
            : null;
        $accessDurationMinutes = $requestKind === QueryRequestKind::QueryAccess
            ? (int) ($data['access_duration_minutes'] ?? 60)
            : null;

        if ($requestedAccessMode === AccessMode::Write) {
            $this->ensureWriteSessionDurationIsAllowed($actor, $databaseConnections, $accessDurationMinutes ?? 60, $accessTransport);
        }

        $requiresApproval = $this->requiresApproval(
            $actor,
            $databaseConnections,
            $statements,
            $requestedAccessMode,
            $accessTransport,
        );

        return DB::transaction(function () use ($queryRequest, $actor, $databaseConnection, $databaseConnections, $data, $requestKind, $accessTransport, $statements, $usesEmergencySqlFallback, $queryType, $requestedAccessMode, $requiresApproval, $scheduledAt, $accessDurationMinutes): QueryRequest {
            $lockedQueryRequest = QueryRequest::query()->lockForUpdate()->findOrFail($queryRequest->id);

            if (! $lockedQueryRequest->isEditable()) {
                throw ValidationException::withMessages([
                    'query_request' => 'This query request can no longer be edited.',
                ]);
            }

            if ($lockedQueryRequest->access_transport !== $accessTransport) {
                throw ValidationException::withMessages([
                    'access_transport' => 'The request transport cannot be changed after creation.',
                ]);
            }

            if ($lockedQueryRequest->request_kind !== $requestKind) {
                throw ValidationException::withMessages([
                    'request_kind' => 'The request type cannot be changed after creation.',
                ]);
            }

            $previousStatus = $lockedQueryRequest->status;
            $submittingDraft = $previousStatus === QueryRequestStatus::Draft;
            $status = $submittingDraft
                ? (! $requiresApproval
                    ? ($scheduledAt?->isFuture() ? QueryRequestStatus::Scheduled : QueryRequestStatus::Approved)
                    : QueryRequestStatus::PendingReview)
                : QueryRequestStatus::PendingReview;

            $lockedQueryRequest->forceFill([
                'database_connection_id' => $databaseConnection->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'sql' => $statements[0]['sql'] ?? '',
                'query_type' => $queryType,
                'request_kind' => $requestKind,
                'access_transport' => $accessTransport,
                'requested_access_mode' => $requestedAccessMode,
                'status' => $status,
                'requires_approval' => $submittingDraft ? $requiresApproval : true,
                'scheduled_at' => $scheduledAt,
                'access_duration_minutes' => $accessDurationMinutes,
                'approved_by_id' => $submittingDraft && ! $requiresApproval ? $actor->id : null,
                'approved_at' => $submittingDraft && ! $requiresApproval ? now() : null,
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
                $report = $this->refreshDeploymentPreflight($lockedQueryRequest);

                if ($submittingDraft && $report['status'] === PreflightStatus::Blocked) {
                    throw ValidationException::withMessages([
                        'statements' => 'This deployment batch is blocked by its latest preflight checks.',
                    ]);
                }
            }

            $this->auditLogger->log('query_request.updated', $actor, $lockedQueryRequest, [
                'previous_status' => $previousStatus->value,
                'approval_invalidated' => in_array($previousStatus, [QueryRequestStatus::Approved, QueryRequestStatus::Scheduled], true),
                'status' => $lockedQueryRequest->status->value,
                'query_type' => $lockedQueryRequest->query_type->value,
                'request_kind' => $lockedQueryRequest->request_kind->value,
                'access_transport' => $lockedQueryRequest->access_transport->value,
                'requested_access_mode' => $lockedQueryRequest->requested_access_mode?->value,
                'database_connection_ids' => $databaseConnections->pluck('id')->values()->all(),
                'statement_count' => count($statements),
                'uses_emergency_sql_fallback' => $usesEmergencySqlFallback,
            ]);

            if ($usesEmergencySqlFallback) {
                $this->auditLogger->log('query_request.emergency_sql_fallback_used', $actor, $lockedQueryRequest, [
                    'statement_positions' => collect($statements)
                        ->filter(fn (array $statement): bool => $this->queryGuard->usesEmergencySqlFallback($statement['sql']))
                        ->pluck('position')
                        ->values()
                        ->all(),
                ]);
            }

            if ($submittingDraft && $requiresApproval) {
                $this->notificationDispatcher->requestSubmitted($lockedQueryRequest);
            } elseif (! $submittingDraft) {
                $this->notificationDispatcher->reapprovalRequired($lockedQueryRequest);
            }

            return $lockedQueryRequest->refresh();
        });
    }

    /**
     * @param  array{database_connection_id?:int,database_connection_ids?:array<int, int>,request_kind:string,title:string,description?:string|null,sql?:string|null,statements?:array<int, array{sql:string,database_connection_id:int}>,scheduled_at?:string|null,access_duration_minutes?:int|null}  $data
     *
     * @throws ValidationException
     */
    public function updateDraft(QueryRequest $queryRequest, User $actor, array $data): QueryRequest
    {
        $this->ensureAccessTransportIsValidForRequestKind(
            $this->accessTransport($data),
            QueryRequestKind::from($data['request_kind']),
        );
        $this->ensureDeploymentBatch($data);

        $statements = $this->draftStatements($this->statementInput($data));
        $databaseConnections = $this->databaseConnectionsForStatements($statements);
        $databaseConnection = $databaseConnections->get($statements[0]['database_connection_id']);

        if (! $databaseConnection instanceof DatabaseConnection) {
            throw ValidationException::withMessages([
                'database_connection_id' => 'Select a valid database connection.',
            ]);
        }

        $scheduledAt = filled($data['scheduled_at'] ?? null)
            ? Carbon::parse($data['scheduled_at'])
            : null;

        return DB::transaction(function () use ($queryRequest, $actor, $data, $statements, $databaseConnection, $scheduledAt): QueryRequest {
            $lockedQueryRequest = QueryRequest::query()->lockForUpdate()->findOrFail($queryRequest->id);

            if ($lockedQueryRequest->status !== QueryRequestStatus::Draft) {
                throw ValidationException::withMessages([
                    'query_request' => 'Only deployment drafts can be saved as drafts.',
                ]);
            }

            $lockedQueryRequest->forceFill([
                'database_connection_id' => $databaseConnection->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'sql' => $statements[0]['sql'],
                'query_type' => QueryType::Write,
                'scheduled_at' => $scheduledAt,
                'requires_approval' => true,
                'approved_by_id' => null,
                'approved_at' => null,
                'dispatched_by_id' => null,
                'dispatched_at' => null,
                'completed_at' => null,
                'result_summary' => null,
                'last_error' => null,
            ])->save();

            $this->replaceStatements($lockedQueryRequest, $statements);
            $this->refreshDeploymentPreflight($lockedQueryRequest);

            $this->auditLogger->log('query_request.draft_saved', $actor, $lockedQueryRequest, [
                'statement_count' => count($statements),
                'database_connection_ids' => collect($statements)
                    ->pluck('database_connection_id')
                    ->unique()
                    ->values()
                    ->all(),
            ]);

            return $lockedQueryRequest->refresh();
        });
    }

    /**
     * @throws ValidationException
     */
    public function runPreflight(QueryRequest $queryRequest, User $actor): QueryRequest
    {
        return DB::transaction(function () use ($queryRequest, $actor): QueryRequest {
            $lockedQueryRequest = QueryRequest::query()->lockForUpdate()->findOrFail($queryRequest->id);

            if ($lockedQueryRequest->request_kind !== QueryRequestKind::SingleExecution
                || ! $lockedQueryRequest->isEditable()) {
                throw ValidationException::withMessages([
                    'query_request' => 'Only editable deployment batches can run preflight.',
                ]);
            }

            $report = $this->refreshDeploymentPreflight($lockedQueryRequest);

            $this->auditLogger->log('query_request.preflight_requested', $actor, $lockedQueryRequest, [
                'status' => $report['status']->value,
                'blocker_count' => $report['summary']['blocker_count'],
                'warning_count' => $report['summary']['warning_count'],
            ]);

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
                QueryRequestStatus::Draft,
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

        return $failedExecution?->statement->position ?? 1;
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
        $firstStatementConnection = null;
        $firstStatementSql = null;

        if ($sourceRequest->request_kind === QueryRequestKind::SingleExecution && $statements->isNotEmpty()) {
            $firstStatement = $statements->first();
            $firstStatementConnection = $firstStatement->databaseConnection;
            $firstStatementSql = $firstStatement->sql;
        }

        $databaseConnection = $sourceRequest->request_kind === QueryRequestKind::SingleExecution
            ? $firstStatementConnection ?? $sourceRequest->databaseConnection
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

            $this->ensureCanRequestAccess($requester, $databaseConnections, $requestedAccessMode, $sourceRequest->access_transport);

            if ($requestedAccessMode === AccessMode::Write) {
                $this->ensureWriteSessionDurationIsAllowed(
                    $requester,
                    $databaseConnections,
                    $sourceRequest->access_duration_minutes ?? 60,
                    $sourceRequest->access_transport,
                );
            }

            $requiresApproval = $this->requiresApproval(
                $requester,
                $databaseConnections,
                [],
                $requestedAccessMode,
                $sourceRequest->access_transport,
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
            'sql' => $firstStatementSql ?? $sourceRequest->sql,
            'query_type' => $sourceRequest->query_type,
            'request_kind' => $sourceRequest->request_kind,
            'access_transport' => $sourceRequest->access_transport,
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
            'access_transport' => $retryRequest->access_transport->value,
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
    private function ensureCanRequestAccess(User $user, Collection $databaseConnections, AccessMode $requestedAccessMode, AccessTransport $accessTransport): void
    {
        if ($user->isAdmin()) {
            return;
        }

        foreach ($databaseConnections as $connection) {
            $queryType = $this->queryTypeForAccessMode($requestedAccessMode);
            $permission = $accessTransport === AccessTransport::NativeProxy
                ? $user->effectiveNativeProxyPermissionFor($connection, $queryType)
                : $user->effectiveQueryAccessPermissionFor($connection, $queryType);

            $accessMode = $accessTransport === AccessTransport::NativeProxy
                ? $permission['native_proxy_access_mode']
                : $permission['query_access_mode'];

            if (! $accessMode->allows($queryType)) {
                throw ValidationException::withMessages([
                    $accessTransport === AccessTransport::NativeProxy ? 'requested_access_mode' : 'database_connection_ids' => $accessTransport === AccessTransport::NativeProxy
                        ? 'Your role is not allowed to request the selected Native Client Access level for this database.'
                        : 'Your role is not allowed to request the selected session access level for every selected database.',
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
    private function requiresApproval(User $user, Collection $databaseConnections, array $statements, ?AccessMode $requestedAccessMode, AccessTransport $accessTransport = AccessTransport::Browser): bool
    {
        if ($user->isAdmin()) {
            return false;
        }

        if ($requestedAccessMode instanceof AccessMode) {
            $queryType = $this->queryTypeForAccessMode($requestedAccessMode);

            return $databaseConnections->contains(function (DatabaseConnection $connection) use ($user, $queryType, $accessTransport): bool {
                $permission = $accessTransport === AccessTransport::NativeProxy
                    ? $user->effectiveNativeProxyPermissionFor($connection, $queryType)
                    : $user->effectiveQueryAccessPermissionFor($connection, $queryType);

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
    private function ensureWriteSessionDurationIsAllowed(User $user, Collection $databaseConnections, int $durationMinutes, AccessTransport $accessTransport = AccessTransport::Browser): void
    {
        if ($user->isAdmin()) {
            return;
        }

        foreach ($databaseConnections as $connection) {
            $permission = $accessTransport === AccessTransport::NativeProxy
                ? $user->effectiveNativeProxyPermissionFor($connection, QueryType::Write)
                : $user->effectiveQueryAccessPermissionFor($connection, QueryType::Write);
            $maximumDuration = $permission['max_write_session_minutes'];

            if ($maximumDuration !== null && $durationMinutes > $maximumDuration) {
                throw ValidationException::withMessages([
                    'access_duration_minutes' => "Write sessions on {$connection->name} are limited to {$maximumDuration} minutes.",
                ]);
            }
        }
    }

    /**
     * @param  array{request_kind?:string}  $data
     *
     * @throws ValidationException
     */
    private function ensureDeploymentBatch(array $data): void
    {
        if (($data['request_kind'] ?? null) !== QueryRequestKind::SingleExecution->value) {
            throw ValidationException::withMessages([
                'request_kind' => 'Only deployment batches can be saved as drafts.',
            ]);
        }
    }

    /**
     * @param  array<int, array{sql?:string,database_connection_id?:int}>  $statements
     * @return array<int, array{position:int, sql:string, query_type:QueryType, database_connection_id:int}>
     *
     * @throws ValidationException
     */
    private function draftStatements(array $statements): array
    {
        if ($statements === []) {
            throw ValidationException::withMessages([
                'statements' => 'At least one SQL statement is required.',
            ]);
        }

        $drafts = [];

        foreach (array_values($statements) as $index => $statement) {
            $databaseConnectionId = (int) ($statement['database_connection_id'] ?? 0);

            if ($databaseConnectionId < 1) {
                throw ValidationException::withMessages([
                    "statements.{$index}.database_connection_id" => 'Select a connection for this statement.',
                ]);
            }

            $sql = (string) ($statement['sql'] ?? '');

            if (blank($sql)) {
                throw ValidationException::withMessages([
                    "statements.{$index}.sql" => 'The SQL statement is required.',
                ]);
            }

            $drafts[] = [
                'position' => $index + 1,
                'database_connection_id' => $databaseConnectionId,
                'sql' => $sql,
                'query_type' => QueryType::Write,
            ];
        }

        return $drafts;
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
    private function databaseConnectionsForAccess(array $data, AccessTransport $accessTransport = AccessTransport::Browser): Collection
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

        if ($accessTransport === AccessTransport::NativeProxy) {
            if ($connectionIds->count() !== 1) {
                throw ValidationException::withMessages([
                    'database_connection_ids' => 'Native Client Access requires exactly one active database connection.',
                ]);
            }

            if (! $databaseConnections->first()?->is_active) {
                throw ValidationException::withMessages([
                    'database_connection_ids' => 'Native Client Access requires an active database connection.',
                ]);
            }
        }

        return $databaseConnections;
    }

    /**
     * @param  array{access_transport?:string}  $data
     *
     * @throws ValidationException
     */
    private function accessTransport(array $data): AccessTransport
    {
        $accessTransport = AccessTransport::tryFrom((string) ($data['access_transport'] ?? AccessTransport::Browser->value));

        if (! $accessTransport instanceof AccessTransport) {
            throw ValidationException::withMessages([
                'access_transport' => 'Choose a supported access transport.',
            ]);
        }

        return $accessTransport;
    }

    /**
     * @throws ValidationException
     */
    private function ensureAccessTransportIsValidForRequestKind(AccessTransport $accessTransport, QueryRequestKind $requestKind): void
    {
        if ($accessTransport === AccessTransport::NativeProxy && $requestKind !== QueryRequestKind::QueryAccess) {
            throw ValidationException::withMessages([
                'access_transport' => 'Native Client Access is available only for Query Access requests.',
            ]);
        }
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
