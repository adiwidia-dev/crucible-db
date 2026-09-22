<?php

namespace App\Services;

use App\Enums\QueryRequestStatus;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\SqlPolicyCandidate;
use App\Models\User;
use App\Services\NativeProxy\NativeProxyStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-type RequestQueueItem array{
 *     id: int,
 *     title: string,
 *     status: 'approved'|'cancelled'|'completed'|'draft'|'failed'|'pending_review'|'rejected'|'running'|'scheduled',
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
 * @phpstan-type PolicyReviewQueueItem array{id:int,statement:string,driver:'pgsql'|'mysql',request_count:int,request_title:string|null,requester:string|null,requested_at:string|null}
 * @phpstan-type OperationalQueueItem array{
 *     id: int,
 *     type: 'active_session'|'failed_execution'|'pending_review'|'policy_review'|'scheduled_execution',
 *     title: string,
 *     connection: string|null,
 *     actor: string|null,
 *     timestamp: string|null,
 *     detail: string|null,
 *     related_title: string|null,
 *     count: int|null,
 *     driver: 'pgsql'|'mysql'|null,
 *     request_kind: 'query_access'|'single_execution'|null,
 *     query_type: 'read'|'write'|null,
 *     requested_access_mode: 'none'|'read'|'write'|null
 * }
 */
class DashboardOverview
{
    public function __construct(private readonly NativeProxyStatus $nativeProxyStatus) {}

    /**
     * @return array{
     *     summary: array{pending_reviews: int, policy_reviews: int, scheduled: int, failed: int, active_sessions: int, native_proxy_connections: int, native_proxy_instances: int},
     *     native_proxy_health: array{status: 'disabled'|'healthy'|'unhealthy'|'version_mismatch', checked_at: string|null, proxy_id: string|null, version: string|null, message: string|null},
     *     native_proxy_status: array{health: array{status: 'disabled'|'healthy'|'unhealthy'|'version_mismatch', checked_at: string|null, proxy_id: string|null, version: string|null, message: string|null}, connections: int, instances: int},
     *     operational_queue: array<int, OperationalQueueItem>
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
        $nativeProxyStatus = $this->nativeProxyStatus->for($user);
        $policyReviewCandidates = SqlPolicyCandidate::query()
            ->whereNull('resolution')
            ->whereHas('occurrences', fn (Builder $query) => $query->whereNotNull('review_requested_at'));

        $summary = [
            'pending_reviews' => (clone $pendingReviews)->count(),
            'policy_reviews' => $isAdmin ? (clone $policyReviewCandidates)->count() : 0,
            'scheduled' => (clone $scheduledRequests)->count(),
            'failed' => (clone $failedRequests)->count(),
            'active_sessions' => (clone $expiringSessions)->count(),
            'native_proxy_connections' => $nativeProxyStatus['connections'],
            'native_proxy_instances' => $nativeProxyStatus['instances'],
        ];

        $pendingReviewItems = $this->requestQueue($pendingReviews);
        $scheduledRequestItems = $this->requestQueue($scheduledRequests);
        $failedRequestItems = $this->requestQueue($failedRequests);
        $expiringSessionItems = $this->sessionQueue($expiringSessions);
        $policyReviewItems = $isAdmin
            ? $this->policyReviewQueue($policyReviewCandidates)
            : [];

        return [
            'summary' => $summary,
            'native_proxy_health' => $nativeProxyStatus['health'],
            'native_proxy_status' => $nativeProxyStatus,
            'operational_queue' => $this->operationalQueue(
                failedRequests: $failedRequestItems,
                pendingReviews: $pendingReviewItems,
                policyReviews: $policyReviewItems,
                activeSessions: $expiringSessionItems,
                scheduledRequests: $scheduledRequestItems,
            ),
        ];
    }

    /**
     * @param  array<int, RequestQueueItem>  $failedRequests
     * @param  array<int, RequestQueueItem>  $pendingReviews
     * @param  array<int, PolicyReviewQueueItem>  $policyReviews
     * @param  array<int, SessionQueueItem>  $activeSessions
     * @param  array<int, RequestQueueItem>  $scheduledRequests
     * @return array<int, OperationalQueueItem>
     */
    private function operationalQueue(
        array $failedRequests,
        array $pendingReviews,
        array $policyReviews,
        array $activeSessions,
        array $scheduledRequests,
    ): array {
        return [
            ...array_map(
                fn (array $request): array => $this->requestOperationalQueueItem($request, 'failed_execution'),
                $failedRequests,
            ),
            ...array_map(
                fn (array $request): array => $this->requestOperationalQueueItem($request, 'pending_review'),
                $pendingReviews,
            ),
            ...array_map(
                fn (array $review): array => $this->policyOperationalQueueItem($review),
                $policyReviews,
            ),
            ...array_map(
                fn (array $session): array => $this->sessionOperationalQueueItem($session),
                $activeSessions,
            ),
            ...array_map(
                fn (array $request): array => $this->requestOperationalQueueItem($request, 'scheduled_execution'),
                $scheduledRequests,
            ),
        ];
    }

    /**
     * @param  RequestQueueItem  $request
     * @param  'failed_execution'|'pending_review'|'scheduled_execution'  $type
     * @return OperationalQueueItem
     */
    private function requestOperationalQueueItem(array $request, string $type): array
    {
        $timestamp = match ($type) {
            'failed_execution' => $request['completed_at'],
            'pending_review' => $request['created_at'],
            'scheduled_execution' => $request['scheduled_at'],
        };

        return [
            'id' => $request['id'],
            'type' => $type,
            'title' => $request['title'],
            'connection' => $request['connection'],
            'actor' => $request['requester'],
            'timestamp' => $timestamp,
            'detail' => $request['last_error'],
            'related_title' => null,
            'count' => null,
            'driver' => null,
            'request_kind' => $request['request_kind'],
            'query_type' => $request['query_type'],
            'requested_access_mode' => $request['requested_access_mode'],
        ];
    }

    /**
     * @param  PolicyReviewQueueItem  $review
     * @return OperationalQueueItem
     */
    private function policyOperationalQueueItem(array $review): array
    {
        return [
            'id' => $review['id'],
            'type' => 'policy_review',
            'title' => $review['statement'],
            'connection' => null,
            'actor' => $review['requester'],
            'timestamp' => $review['requested_at'],
            'detail' => null,
            'related_title' => $review['request_title'],
            'count' => $review['request_count'],
            'driver' => $review['driver'],
            'request_kind' => null,
            'query_type' => null,
            'requested_access_mode' => null,
        ];
    }

    /**
     * @param  SessionQueueItem  $session
     * @return OperationalQueueItem
     */
    private function sessionOperationalQueueItem(array $session): array
    {
        return [
            'id' => $session['id'],
            'type' => 'active_session',
            'title' => $session['title'],
            'connection' => $session['connection'],
            'actor' => $session['user'],
            'timestamp' => $session['expires_at'],
            'detail' => null,
            'related_title' => null,
            'count' => null,
            'driver' => null,
            'request_kind' => null,
            'query_type' => null,
            'requested_access_mode' => null,
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
     * @param  Builder<SqlPolicyCandidate>  $candidates
     * @return array<int, PolicyReviewQueueItem>
     */
    private function policyReviewQueue(Builder $candidates): array
    {
        return $candidates
            ->with([
                'occurrences' => fn ($query) => $query
                    ->whereNotNull('review_requested_at')
                    ->with('queryRequest.requester:id,name')
                    ->latest('review_requested_at'),
            ])
            ->latest('last_seen_at')
            ->limit(5)
            ->get()
            ->map(function (SqlPolicyCandidate $candidate): array {
                $latestOccurrence = $candidate->occurrences->first();

                return [
                    'id' => $candidate->id,
                    'statement' => $candidate->shape_label ?? $candidate->canonical_sql,
                    'driver' => $candidate->database_driver->value,
                    'request_count' => $candidate->occurrences->unique('query_request_id')->count(),
                    'request_title' => $latestOccurrence?->queryRequest?->title,
                    'requester' => $latestOccurrence?->queryRequest?->requester?->name,
                    'requested_at' => $this->dateString($latestOccurrence?->review_requested_at),
                ];
            })
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
