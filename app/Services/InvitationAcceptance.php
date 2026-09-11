<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class InvitationAcceptance
{
    public function ensureCanAccept(User $user, string $token): void
    {
        abort_unless($this->isPending($user), 403);
        abort_unless(hash_equals((string) $user->invitation_token_hash, hash('sha256', $token)), 403);
    }

    public function isPending(User $user): bool
    {
        return $user->invited_at !== null
            && $user->invitation_accepted_at === null
            && $user->invitation_token_hash !== null
            && $user->invited_at->isAfter(now()->subDays((int) config('auth.invitation_expiration_days')));
    }

    public function pendingUserByEmail(string $email): ?User
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->where('invited_at', '>', now()->subDays((int) config('auth.invitation_expiration_days')))
            ->whereNull('invitation_accepted_at')
            ->whereNotNull('invitation_token_hash')
            ->first();
    }
}
