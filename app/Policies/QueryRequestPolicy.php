<?php

namespace App\Policies;

use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\User;
use Illuminate\Support\Collection;

class QueryRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, QueryRequest $queryRequest): bool
    {
        return $user->isAdmin()
            || $queryRequest->requester_id === $user->id
            || $this->scopedConnections($queryRequest)->every(
                fn ($connection): bool => $user->canAccessDatabase($connection)
                    || $user->canReviewDatabase($connection),
            );
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, QueryRequest $queryRequest): bool
    {
        return ($user->isAdmin() || $queryRequest->requester_id === $user->id)
            && $queryRequest->isEditable();
    }

    public function delete(User $user, QueryRequest $queryRequest): bool
    {
        return $user->isAdmin()
            && $queryRequest->request_kind === QueryRequestKind::QueryAccess;
    }

    public function restore(User $user, QueryRequest $queryRequest): bool
    {
        return false;
    }

    public function forceDelete(User $user, QueryRequest $queryRequest): bool
    {
        return false;
    }

    public function review(User $user, QueryRequest $queryRequest): bool
    {
        return $queryRequest->status === QueryRequestStatus::PendingReview
        && $queryRequest->requester_id !== $user->id
        && $this->scopedConnections($queryRequest)->every(
            fn ($connection): bool => $user->canReviewDatabase($connection),
        );
    }

    public function dispatch(User $user, QueryRequest $queryRequest): bool
    {
        return $queryRequest->request_kind === QueryRequestKind::SingleExecution
            && $queryRequest->status === QueryRequestStatus::Approved
            && $queryRequest->dispatched_at === null
            && ($user->isAdmin() || $queryRequest->requester_id === $user->id || $this->scopedConnections($queryRequest)->every(
                fn ($connection): bool => $user->canReviewDatabase($connection),
            ));
    }

    public function startSession(User $user, QueryRequest $queryRequest): bool
    {
        return $queryRequest->request_kind === QueryRequestKind::QueryAccess
            && $queryRequest->status === QueryRequestStatus::Approved
            && ($user->isAdmin() || $queryRequest->requester_id === $user->id);
    }

    /**
     * @return Collection<int, DatabaseConnection>
     */
    private function scopedConnections(QueryRequest $queryRequest): Collection
    {
        $queryRequest->loadMissing('databaseConnection', 'accessConnections', 'statements.databaseConnection');

        if ($queryRequest->request_kind === QueryRequestKind::QueryAccess
            && $queryRequest->accessConnections->isNotEmpty()) {
            return $queryRequest->accessConnections;
        }

        $connections = $queryRequest->statements
            ->map(fn ($statement) => $statement->databaseConnection ?? $queryRequest->databaseConnection)
            ->unique('id')
            ->values();

        return $connections->isNotEmpty()
            ? $connections
            : collect([$queryRequest->databaseConnection]);
    }
}
