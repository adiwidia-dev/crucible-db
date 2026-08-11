<?php

namespace App\Services;

use App\Enums\AccessMode;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Jobs\ExecuteQueryRequest;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\QueryReview;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QueryRequestWorkflow
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly QueryGuard $queryGuard,
    ) {}

    /**
     * @param  array{database_connection_id:int,request_kind:string,title:string,description?:string|null,sql?:string|null,scheduled_at?:string|null,access_duration_minutes?:int|null}  $data
     *
     * @throws ValidationException
     */
    public function create(User $requester, DatabaseConnection $databaseConnection, array $data): QueryRequest
    {
        $requestKind = QueryRequestKind::from($data['request_kind']);
        $queryType = QueryType::Read;
        $sql = '';
        $permission = $requester->effectiveDatabasePermission($databaseConnection);

        if ($requestKind === QueryRequestKind::SingleExecution) {
            $queryType = $this->queryGuard->classify((string) ($data['sql'] ?? ''));
            $sql = $this->queryGuard->validateExecutable((string) ($data['sql'] ?? ''));
        }

        if (! $requester->isAdmin()) {
            if ($requestKind === QueryRequestKind::SingleExecution && ! $permission['access_mode']->allows($queryType)) {
                throw ValidationException::withMessages([
                    'database_connection_id' => 'Your role is not allowed to run this query type on the selected database.',
                ]);
            }

            if ($requestKind === QueryRequestKind::QueryAccess && $permission['access_mode'] === AccessMode::None) {
                throw ValidationException::withMessages([
                    'database_connection_id' => 'Your role is not allowed to access the selected database.',
                ]);
            }
        }

        $requiresApproval = ! $requester->isAdmin() && $permission['requires_approval'];
        $scheduledAt = $requestKind === QueryRequestKind::SingleExecution && filled($data['scheduled_at'] ?? null)
            ? Carbon::parse($data['scheduled_at'])
            : null;
        $accessDurationMinutes = $requestKind === QueryRequestKind::QueryAccess
            ? (int) ($data['access_duration_minutes'] ?? 60)
            : null;

        return DB::transaction(function () use ($requester, $databaseConnection, $data, $requestKind, $queryType, $sql, $requiresApproval, $scheduledAt, $accessDurationMinutes): QueryRequest {
            $status = QueryRequestStatus::PendingReview;

            if (! $requiresApproval) {
                $status = $scheduledAt?->isFuture() ? QueryRequestStatus::Scheduled : QueryRequestStatus::Approved;
            }

            $queryRequest = QueryRequest::query()->create([
                'requester_id' => $requester->id,
                'database_connection_id' => $databaseConnection->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'sql' => $sql,
                'query_type' => $queryType,
                'request_kind' => $requestKind,
                'status' => $status,
                'requires_approval' => $requiresApproval,
                'scheduled_at' => $scheduledAt,
                'access_duration_minutes' => $accessDurationMinutes,
                'approved_by_id' => $requiresApproval ? null : $requester->id,
                'approved_at' => $requiresApproval ? null : now(),
            ]);

            $this->auditLogger->log('query_request.created', $requester, $queryRequest, [
                'status' => $queryRequest->status->value,
                'query_type' => $queryRequest->query_type->value,
                'request_kind' => $queryRequest->request_kind->value,
                'database_connection_id' => $databaseConnection->id,
            ]);

            return $queryRequest;
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

            return $review;
        });
    }

    public function dispatch(QueryRequest $queryRequest, ?User $actor = null): void
    {
        if ($queryRequest->request_kind !== QueryRequestKind::SingleExecution) {
            throw ValidationException::withMessages([
                'query_request' => 'Only single execution requests can be dispatched.',
            ]);
        }

        if ($queryRequest->status === QueryRequestStatus::Scheduled && $queryRequest->scheduled_at?->isFuture()) {
            throw ValidationException::withMessages([
                'query_request' => 'This query request is scheduled for a future execution time.',
            ]);
        }

        if ($queryRequest->dispatched_at !== null || ! in_array($queryRequest->status, [QueryRequestStatus::Approved, QueryRequestStatus::Scheduled], true)) {
            return;
        }

        $queryRequest->forceFill([
            'status' => QueryRequestStatus::Approved,
            'dispatched_at' => now(),
            'dispatched_by_id' => $actor?->id,
        ])->save();

        ExecuteQueryRequest::dispatch($queryRequest->id)->onQueue('queries')->afterCommit();

        $this->auditLogger->log('query_request.dispatched', $actor, $queryRequest);
    }
}
