<?php

namespace App\Policies;

use App\Models\QuerySession;
use App\Models\User;

class QuerySessionPolicy
{
    public function view(User $user, QuerySession $querySession): bool
    {
        return $user->isAdmin()
            || $querySession->user_id === $user->id
            || $user->canReviewDatabase($querySession->databaseConnection);
    }

    public function use(User $user, QuerySession $querySession): bool
    {
        return $querySession->isActive()
            && ($user->isAdmin() || $querySession->user_id === $user->id);
    }

    public function submitQuery(User $user, QuerySession $querySession): bool
    {
        return $user->isAdmin() || $querySession->user_id === $user->id;
    }

    public function end(User $user, QuerySession $querySession): bool
    {
        return $user->isAdmin() || $querySession->user_id === $user->id;
    }
}
