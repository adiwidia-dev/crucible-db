<?php

namespace App\Policies;

use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Models\QueryRequest;
use App\Models\User;

class QueryRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, QueryRequest $queryRequest): bool
    {
        $queryRequest->loadMissing('databaseConnection');

        return $user->isAdmin()
            || $queryRequest->requester_id === $user->id
            || $user->canAccessDatabase($queryRequest->databaseConnection)
            || $user->canReviewDatabase($queryRequest->databaseConnection);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, QueryRequest $queryRequest): bool
    {
        return false;
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
            && $user->canReviewDatabase($queryRequest->databaseConnection);
    }

    public function dispatch(User $user, QueryRequest $queryRequest): bool
    {
        return $queryRequest->request_kind === QueryRequestKind::SingleExecution
            && $queryRequest->status === QueryRequestStatus::Approved
            && $queryRequest->dispatched_at === null
            && ($user->isAdmin() || $queryRequest->requester_id === $user->id || $user->canReviewDatabase($queryRequest->databaseConnection));
    }

    public function startSession(User $user, QueryRequest $queryRequest): bool
    {
        return $queryRequest->request_kind === QueryRequestKind::QueryAccess
            && $queryRequest->status === QueryRequestStatus::Approved
            && ($user->isAdmin() || $queryRequest->requester_id === $user->id);
    }
}
