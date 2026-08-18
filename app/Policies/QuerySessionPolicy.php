<?php

namespace App\Policies;

use App\Models\DatabaseConnection;
use App\Models\QuerySession;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class QuerySessionPolicy
{
    public function view(User $user, QuerySession $querySession): bool
    {
        return $user->isAdmin()
            || $querySession->user_id === $user->id
            || $this->sessionConnections($querySession)->every(
                fn (DatabaseConnection $connection): bool => $user->canReviewDatabase($connection),
            );
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

    /**
     * @return Collection<int, DatabaseConnection>
     */
    private function sessionConnections(QuerySession $querySession): Collection
    {
        $querySession->loadMissing('databaseConnection', 'databaseConnections');

        return $querySession->databaseConnections->isNotEmpty()
            ? $querySession->databaseConnections
            : new Collection([$querySession->databaseConnection]);
    }
}
