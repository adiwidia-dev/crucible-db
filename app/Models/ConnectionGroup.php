<?php

namespace App\Models;

use Database\Factories\ConnectionGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 */
#[Fillable(['name', 'description'])]
class ConnectionGroup extends Model
{
    /** @use HasFactory<ConnectionGroupFactory> */
    use HasFactory;

    /**
     * @return BelongsToMany<DatabaseConnection, $this>
     */
    public function databaseConnections(): BelongsToMany
    {
        return $this->belongsToMany(DatabaseConnection::class)
            ->withTimestamps()
            ->orderBy('database_connections.name');
    }

    /**
     * @return HasMany<RoleConnectionGroupPolicy, $this>
     */
    public function rolePolicies(): HasMany
    {
        return $this->hasMany(RoleConnectionGroupPolicy::class);
    }
}
