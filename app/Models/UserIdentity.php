<?php

namespace App\Models;

use Database\Factories\UserIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $auth_provider_id
 * @property string $provider
 * @property string $provider_user_id
 * @property string $email
 * @property string|null $name
 * @property string|null $avatar
 */
#[Fillable(['user_id', 'auth_provider_id', 'provider', 'provider_user_id', 'email', 'name', 'avatar'])]
class UserIdentity extends Model
{
    /** @use HasFactory<UserIdentityFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<AuthProvider, $this>
     */
    public function authProvider(): BelongsTo
    {
        return $this->belongsTo(AuthProvider::class);
    }
}
