<?php

namespace App\Policies;

use App\Models\DatabaseConnection;
use App\Models\User;

class DatabaseConnectionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DatabaseConnection $databaseConnection): bool
    {
        return $databaseConnection->is_active
            && $user->canAccessDatabase($databaseConnection);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, DatabaseConnection $databaseConnection): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, DatabaseConnection $databaseConnection): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, DatabaseConnection $databaseConnection): bool
    {
        return false;
    }

    public function forceDelete(User $user, DatabaseConnection $databaseConnection): bool
    {
        return false;
    }
}
