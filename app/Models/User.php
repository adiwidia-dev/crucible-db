<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccessMode;
use App\Enums\QueryType;
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
#[Fillable(['role_id', 'first_name', 'last_name', 'name', 'email', 'timezone', 'password', 'invited_by_id', 'invited_at', 'invitation_accepted_at', 'disabled_at', 'invitation_token_hash', 'notification_preferences', 'is_operational_alert_recipient'])]
#[Hidden(['password', 'invitation_token_hash', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** @var Collection<int, Role>|null */
    private ?Collection $authorizationRoles = null;

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
            'notification_preferences' => 'array',
            'is_operational_alert_recipient' => 'boolean',
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

    /**
     * @return HasMany<NotificationSubscription, $this>
     */
    public function notificationSubscriptions(): HasMany
    {
        return $this->hasMany(NotificationSubscription::class);
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

    public function refresh(): static
    {
        $this->authorizationRoles = null;

        return parent::refresh();
    }

    /**
     * @return array{access_mode: AccessMode, can_review: bool, read_requires_approval: bool, write_requires_approval: bool, max_write_session_minutes: int|null}
     */
    public function effectiveDatabasePermission(DatabaseConnection $databaseConnection): array
    {
        if ($this->isAdmin()) {
            return [
                'access_mode' => AccessMode::Write,
                'can_review' => true,
                'read_requires_approval' => false,
                'write_requires_approval' => false,
                'max_write_session_minutes' => null,
            ];
        }

        foreach ($this->authorizationRoles() as $role) {
            $permission = $this->permissionForRole($role, $databaseConnection);

            if ($permission !== null) {
                return $permission;
            }
        }

        return $this->noDatabasePermission();
    }

    /**
     * Resolve the first ordered role policy that grants the requested query type.
     *
     * @return array{access_mode: AccessMode, read_requires_approval: bool, write_requires_approval: bool, max_write_session_minutes: int|null}
     */
    public function effectiveDatabasePermissionFor(DatabaseConnection $databaseConnection, QueryType $queryType): array
    {
        if ($this->isAdmin()) {
            return [
                'access_mode' => AccessMode::Write,
                'read_requires_approval' => false,
                'write_requires_approval' => false,
                'max_write_session_minutes' => null,
            ];
        }

        foreach ($this->authorizationRoles() as $role) {
            $permission = $this->permissionForRole($role, $databaseConnection);

            if ($permission !== null && $permission['access_mode']->allows($queryType)) {
                return $permission;
            }
        }

        return $this->noDatabasePermission();
    }

    public function canAccessDatabase(DatabaseConnection $databaseConnection): bool
    {
        return $this->effectiveDatabasePermissionFor($databaseConnection, QueryType::Read)['access_mode']
            ->allows(QueryType::Read);
    }

    public function canReviewDatabase(DatabaseConnection $databaseConnection): bool
    {
        return $this->effectiveDatabasePermission($databaseConnection)['can_review'];
    }

    public function wantsNotificationEmail(string $preference, bool $default): bool
    {
        $value = data_get($this->notification_preferences, "email.{$preference}");

        return is_bool($value) ? $value : $default;
    }

    /**
     * @return array<int, int>
     */
    public function accessibleDatabaseConnectionIds(): array
    {
        return $this->candidateDatabaseConnections()
            ->filter(fn (DatabaseConnection $connection): bool => $this->canAccessDatabase($connection))
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    public function reviewableDatabaseConnectionIds(): array
    {
        return $this->candidateDatabaseConnections()
            ->filter(fn (DatabaseConnection $connection): bool => $this->canReviewDatabase($connection))
            ->pluck('id')
            ->values()
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
     * @return Collection<int, Role>
     */
    private function authorizationRoles(): Collection
    {
        if ($this->authorizationRoles instanceof Collection) {
            return $this->authorizationRoles;
        }

        $this->authorizationRoles = $this->roles()
            ->with([
                'databasePermissions',
                'connectionGroupPolicies.connectionGroup.databaseConnections:id',
            ])
            ->get();

        return $this->authorizationRoles;
    }

    /**
     * @return array{access_mode: AccessMode, can_review: bool, read_requires_approval: bool, write_requires_approval: bool, max_write_session_minutes: int|null}|null
     */
    private function permissionForRole(Role $role, DatabaseConnection $databaseConnection): ?array
    {
        $directPermission = $role->databasePermissions
            ->firstWhere('database_connection_id', $databaseConnection->id);

        if ($directPermission instanceof RoleDatabasePermission) {
            return $this->permissionAttributes($directPermission);
        }

        $groupPolicies = $role->connectionGroupPolicies
            ->filter(fn (RoleConnectionGroupPolicy $policy): bool => $policy->connectionGroup
                ->databaseConnections
                ->contains('id', $databaseConnection->id));

        if ($groupPolicies->isEmpty()) {
            return null;
        }

        return $this->mostRestrictiveGroupPermission($groupPolicies);
    }

    /**
     * @param  Collection<int, RoleConnectionGroupPolicy>  $policies
     * @return array{access_mode: AccessMode, can_review: bool, read_requires_approval: bool, write_requires_approval: bool, max_write_session_minutes: int|null}
     */
    private function mostRestrictiveGroupPermission(Collection $policies): array
    {
        $accessMode = $policies
            ->pluck('access_mode')
            ->sortBy(fn (AccessMode $mode): int => match ($mode) {
                AccessMode::None => 0,
                AccessMode::Read => 1,
                AccessMode::Write => 2,
            })
            ->first();

        $writeSessionLimit = $policies
            ->pluck('max_write_session_minutes')
            ->filter(fn (?int $minutes): bool => $minutes !== null)
            ->min();

        return [
            'access_mode' => $accessMode instanceof AccessMode ? $accessMode : AccessMode::None,
            'can_review' => $policies->contains(fn (RoleConnectionGroupPolicy $policy): bool => $policy->can_review),
            'read_requires_approval' => $policies->contains(fn (RoleConnectionGroupPolicy $policy): bool => $policy->read_requires_approval),
            'write_requires_approval' => $policies->contains(fn (RoleConnectionGroupPolicy $policy): bool => $policy->write_requires_approval),
            'max_write_session_minutes' => is_int($writeSessionLimit) ? $writeSessionLimit : null,
        ];
    }

    /**
     * @return array{access_mode: AccessMode, can_review: bool, read_requires_approval: bool, write_requires_approval: bool, max_write_session_minutes: int|null}
     */
    private function permissionAttributes(RoleDatabasePermission|RoleConnectionGroupPolicy $permission): array
    {
        return [
            'access_mode' => $permission->access_mode,
            'can_review' => $permission->can_review,
            'read_requires_approval' => $permission->read_requires_approval,
            'write_requires_approval' => $permission->write_requires_approval,
            'max_write_session_minutes' => $permission->max_write_session_minutes,
        ];
    }

    /**
     * @return Collection<int, DatabaseConnection>
     */
    private function candidateDatabaseConnections(): Collection
    {
        $connectionIds = $this->authorizationRoles()
            ->flatMap(function (Role $role): Collection {
                return $role->databasePermissions
                    ->pluck('database_connection_id')
                    ->merge(
                        $role->connectionGroupPolicies
                            ->flatMap(fn (RoleConnectionGroupPolicy $policy): Collection => $policy->connectionGroup
                                ->databaseConnections
                                ->pluck('id')),
                    );
            })
            ->unique()
            ->values();

        if ($connectionIds->isEmpty()) {
            return collect();
        }

        return DatabaseConnection::query()
            ->whereKey($connectionIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{access_mode: AccessMode, can_review: false, read_requires_approval: true, write_requires_approval: true, max_write_session_minutes: null}
     */
    private function noDatabasePermission(): array
    {
        return [
            'access_mode' => AccessMode::None,
            'can_review' => false,
            'read_requires_approval' => true,
            'write_requires_approval' => true,
            'max_write_session_minutes' => null,
        ];
    }
}
