<?php

namespace App\Services;

use App\Enums\QueryRequestStatus;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-type RequestQueueItem array{
 *     id: int,
 *     title: string,
 *     status: 'approved'|'cancelled'|'completed'|'failed'|'pending_review'|'rejected'|'running'|'scheduled',
 *     request_kind: 'query_access'|'single_execution',
 *     query_type: 'read'|'write',
 *     requested_access_mode: 'none'|'read'|'write'|null,
 *     connection: string,
 *     requester: string,
 *     scheduled_at: string|null,
 *     created_at: string|null,
 *     completed_at: string|null,
 *     last_error: string|null
 * }
 * @phpstan-type SessionQueueItem array{
 *     id: int,
 *     request_id: int,
 *     title: string,
 *     connection: string,
 *     user: string,
 *     expires_at: string
 * }
 */
class DashboardOverview
{
    /**
     * @return array{
     *     summary: array{pending_reviews: int, scheduled: int, failed: int, active_sessions: int},
     *     pending_reviews: array<int, RequestQueueItem>,
     *     scheduled_requests: array<int, RequestQueueItem>,
     *     failed_requests: array<int, RequestQueueItem>,
     *     expiring_sessions: array<int, SessionQueueItem>
     * }
     */
    public function for(User $user): array
    {
        $isAdmin = $user->isAdmin();
        $reviewableConnectionIds = $isAdmin
            ? []
            : $user->reviewableDatabaseConnectionIds();
        $accessibleConnectionIds = $isAdmin
            ? []
            : $user->accessibleDatabaseConnectionIds();
        $visibleConnectionIds = collect($accessibleConnectionIds)
            ->merge($reviewableConnectionIds)
            ->unique()
            ->values()
            ->all();

        $pendingReviews = $this->reviewableRequests($user, $isAdmin, $reviewableConnectionIds)
            ->where('status', QueryRequestStatus::PendingReview)
            ->oldest('created_at');
        $scheduledRequests = $this->visibleRequests($user, $isAdmin, $visibleConnectionIds)
            ->where('status', QueryRequestStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->orderBy('scheduled_at');
        $failedRequests = $this->visibleRequests($user, $isAdmin, $visibleConnectionIds)
            ->where('status', QueryRequestStatus::Failed)
            ->latest('completed_at');
        $expiringSessions = $this->visibleSessions($user, $isAdmin, $reviewableConnectionIds)
            ->whereNull('ended_at')
            ->where('expires_at', '>', now())
            ->orderBy('expires_at');

        return [
            'summary' => [
                'pending_reviews' => (clone $pendingReviews)->count(),
                'scheduled' => (clone $scheduledRequests)->count(),
                'failed' => (clone $failedRequests)->count(),
                'active_sessions' => (clone $expiringSessions)->count(),
            ],
            'pending_reviews' => $this->requestQueue($pendingReviews),
            'scheduled_requests' => $this->requestQueue($scheduledRequests),
            'failed_requests' => $this->requestQueue($failedRequests),
            'expiring_sessions' => $this->sessionQueue($expiringSessions),
        ];
    }

    /**
     * @param  array<int, int>  $visibleConnectionIds
     * @return Builder<QueryRequest>
     */
    private function visibleRequests(User $user, bool $isAdmin, array $visibleConnectionIds): Builder
    {
        $query = QueryRequest::query();

        if ($isAdmin) {
            return $query;
        }

        return $query->where(function (Builder $requests) use ($user, $visibleConnectionIds): void {
            $requests->where('requester_id', $user->id);

            if ($visibleConnectionIds !== []) {
                $requests->orWhereIn('database_connection_id', $visibleConnectionIds);
            }
        });
    }

    /**
     * @param  array<int, int>  $reviewableConnectionIds
     * @return Builder<QueryRequest>
     */
    private function reviewableRequests(User $user, bool $isAdmin, array $reviewableConnectionIds): Builder
    {
        $query = QueryRequest::query()->where('requester_id', '!=', $user->id);

        if ($isAdmin) {
            return $query;
        }

        return $query->whereIn('database_connection_id', $reviewableConnectionIds);
    }

    /**
     * @param  array<int, int>  $reviewableConnectionIds
     * @return Builder<QuerySession>
     */
    private function visibleSessions(User $user, bool $isAdmin, array $reviewableConnectionIds): Builder
    {
        $query = QuerySession::query();

        if ($isAdmin) {
            return $query;
        }

        return $query->where(function (Builder $sessions) use ($user, $reviewableConnectionIds): void {
            $sessions->where('user_id', $user->id);

            if ($reviewableConnectionIds !== []) {
                $sessions->orWhereIn('database_connection_id', $reviewableConnectionIds);
            }
        });
    }

    /**
     * @param  Builder<QueryRequest>  $requests
     * @return array<int, RequestQueueItem>
     */
    private function requestQueue(Builder $requests): array
    {
        $queue = $requests
            ->with([
                'databaseConnection:id,name,driver',
                'requester:id,name',
            ])
            ->limit(5)
            ->get([
                'id',
                'requester_id',
                'database_connection_id',
                'title',
                'status',
                'query_type',
                'request_kind',
                'requested_access_mode',
                'scheduled_at',
                'created_at',
                'completed_at',
                'last_error',
            ])
            ->map(fn (QueryRequest $queryRequest) => $this->requestQueueItem($queryRequest))
            ->values()
            ->all();

        return $queue;
    }

    /**
     * @param  Builder<QuerySession>  $sessions
     * @return array<int, SessionQueueItem>
     */
    private function sessionQueue(Builder $sessions): array
    {
        return $sessions
            ->with([
                'queryRequest:id,title',
                'databaseConnection:id,name',
                'user:id,name',
            ])
            ->limit(5)
            ->get([
                'id',
                'query_request_id',
                'user_id',
                'database_connection_id',
                'expires_at',
            ])
            ->map(fn (QuerySession $querySession) => $this->sessionQueueItem($querySession))
            ->values()
            ->all();
    }

    /**
     * @return RequestQueueItem
     */
    private function requestQueueItem(QueryRequest $queryRequest): array
    {
        $item = [
            'id' => $queryRequest->id,
            'title' => $queryRequest->title,
            'status' => $queryRequest->status->value,
            'request_kind' => $queryRequest->request_kind->value,
            'query_type' => $queryRequest->query_type->value,
            'connection' => $queryRequest->databaseConnection->name,
            'requested_access_mode' => $queryRequest->requested_access_mode?->value,
            'requester' => $queryRequest->requester->name,
            'scheduled_at' => $this->dateString($queryRequest->scheduled_at),
            'created_at' => $this->dateString($queryRequest->created_at),
            'completed_at' => $this->dateString($queryRequest->completed_at),
            'last_error' => $queryRequest->last_error,
        ];

        return $item;
    }

    /**
     * @return SessionQueueItem
     */
    private function sessionQueueItem(QuerySession $querySession): array
    {
        return [
            'id' => $querySession->id,
            'request_id' => $querySession->queryRequest->id,
            'title' => $querySession->queryRequest->title,
            'connection' => $querySession->databaseConnection->name,
            'user' => $querySession->user->name,
            'expires_at' => $querySession->expires_at->toIso8601String(),
        ];
    }

    private function dateString(?CarbonInterface $date): ?string
    {
        return $date?->toIso8601String();
    }
}
