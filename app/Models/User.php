<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccessMode;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property int|null $role_id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $name
 * @property string $email
 * @property string $timezone
 * @property Carbon|null $email_verified_at
 * @property int|null $invited_by_id
 * @property Carbon|null $invited_at
 * @property Carbon|null $invitation_accepted_at
 * @property Carbon|null $disabled_at
 * @property string|null $invitation_token_hash
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Role|null $role
 * @property-read Collection<int, Role> $roles
 * @property-read Collection<int, UserIdentity> $identities
 */
#[Fillable(['role_id', 'first_name', 'last_name', 'name', 'email', 'timezone', 'password', 'invited_by_id', 'invited_at', 'invitation_accepted_at', 'disabled_at', 'invitation_token_hash'])]
#[Hidden(['password', 'invitation_token_hash', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'invited_at' => 'datetime',
            'invitation_accepted_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot('priority')
            ->withTimestamps()
            ->orderByPivot('priority')
            ->orderBy('roles.id');
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return HasMany<UserIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    public function isAdmin(): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->contains(fn (Role $role): bool => $role->is_admin);
        }

        return $this->roles()->where('is_admin', true)->exists();
    }

    public function hasRole(string $slug): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->contains(fn (Role $role): bool => $role->slug === $slug);
        }

        return $this->roles()->where('slug', $slug)->exists();
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * @return array{access_mode: AccessMode, can_review: bool, requires_approval: bool}
     */
    public function effectiveDatabasePermission(DatabaseConnection $databaseConnection): array
    {
        if ($this->isAdmin()) {
            return [
                'access_mode' => AccessMode::Write,
                'can_review' => true,
                'requires_approval' => false,
            ];
        }

        $permissionTable = (new RoleDatabasePermission)->getTable();
        $pivotTable = 'role_user';

        $permission = RoleDatabasePermission::query()
            ->select("{$permissionTable}.*")
            ->join($pivotTable, "{$pivotTable}.role_id", '=', "{$permissionTable}.role_id")
            ->where("{$pivotTable}.user_id", $this->id)
            ->whereBelongsTo($databaseConnection)
            ->orderBy("{$pivotTable}.priority")
            ->orderBy("{$pivotTable}.role_id")
            ->first();

        if (! $permission instanceof RoleDatabasePermission) {
            return [
                'access_mode' => AccessMode::None,
                'can_review' => false,
                'requires_approval' => true,
            ];
        }

        return [
            'access_mode' => $permission->access_mode,
            'can_review' => $permission->can_review,
            'requires_approval' => $permission->requires_approval,
        ];
    }

    public function canAccessDatabase(DatabaseConnection $databaseConnection): bool
    {
        return $this->effectiveDatabasePermission($databaseConnection)['access_mode']
            ->allows($databaseConnection->minimumQueryType());
    }

    public function canReviewDatabase(DatabaseConnection $databaseConnection): bool
    {
        return $this->effectiveDatabasePermission($databaseConnection)['can_review'];
    }

    /**
     * @return array<int, int>
     */
    public function accessibleDatabaseConnectionIds(): array
    {
        return $this->effectiveRoleDatabasePermissions()
            ->filter(fn (RoleDatabasePermission $permission): bool => $permission->access_mode !== AccessMode::None)
            ->keys()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    public function reviewableDatabaseConnectionIds(): array
    {
        return $this->effectiveRoleDatabasePermissions()
            ->filter(fn (RoleDatabasePermission $permission): bool => $permission->can_review)
            ->keys()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    public function roleIdsForAccess(): array
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->pluck('id')->all();
        }

        return $this->roles()->pluck('roles.id')->all();
    }

    /**
     * @return Collection<int, RoleDatabasePermission>
     */
    private function effectiveRoleDatabasePermissions(): Collection
    {
        if ($this->isAdmin()) {
            return collect();
        }

        $permissionTable = (new RoleDatabasePermission)->getTable();
        $pivotTable = 'role_user';

        return RoleDatabasePermission::query()
            ->select("{$permissionTable}.*")
            ->join($pivotTable, "{$pivotTable}.role_id", '=', "{$permissionTable}.role_id")
            ->where("{$pivotTable}.user_id", $this->id)
            ->orderBy("{$pivotTable}.priority")
            ->orderBy("{$pivotTable}.role_id")
            ->get()
            ->unique('database_connection_id')
            ->keyBy('database_connection_id');
    }
}
