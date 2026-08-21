<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_admin
 * @property-read Pivot|null $pivot
 * @property-read int|null $users_count
 * @property-read int|null $database_permissions_count
 */
#[Fillable(['name', 'slug', 'description', 'is_admin'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('priority')
            ->withTimestamps()
            ->orderByPivot('priority')
            ->orderBy('users.id');
    }

    /**
     * @return HasMany<RoleDatabasePermission, $this>
     */
    public function databasePermissions(): HasMany
    {
        return $this->hasMany(RoleDatabasePermission::class);
    }

    /**
     * @return HasMany<RoleConnectionGroupPolicy, $this>
     */
    public function connectionGroupPolicies(): HasMany
    {
        return $this->hasMany(RoleConnectionGroupPolicy::class);
    }
}
