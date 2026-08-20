<?php

namespace App\Models;

use App\Enums\DatabaseDriver;
use App\Enums\QueryType;
use Database\Factories\DatabaseConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property int|null $created_by_id
 * @property string $name
 * @property DatabaseDriver $driver
 * @property string $host
 * @property int $port
 * @property string $database
 * @property string $username
 * @property string $password
 * @property string|null $ssl_mode
 * @property bool $is_active
 * @property-read User|null $createdBy
 */
#[Fillable(['created_by_id', 'name', 'driver', 'host', 'port', 'database', 'username', 'password', 'ssl_mode', 'is_active'])]
#[Hidden(['password'])]
class DatabaseConnection extends Model
{
    /** @use HasFactory<DatabaseConnectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'driver' => DatabaseDriver::class,
            'password' => 'encrypted',
            'is_active' => 'boolean',
            'port' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return HasMany<RoleDatabasePermission, $this>
     */
    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RoleDatabasePermission::class);
    }

    /**
     * @return HasMany<QueryRequest, $this>
     */
    public function queryRequests(): HasMany
    {
        return $this->hasMany(QueryRequest::class);
    }

    /**
     * @return MorphMany<NotificationSubscription, $this>
     */
    public function notificationSubscriptions(): MorphMany
    {
        return $this->morphMany(NotificationSubscription::class, 'subscribable');
    }

    public function minimumQueryType(): QueryType
    {
        return QueryType::Read;
    }
}
