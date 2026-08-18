<?php

namespace App\Models;

use Database\Factories\QuerySessionFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $query_request_id
 * @property int $user_id
 * @property int $database_connection_id
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read QueryRequest $queryRequest
 * @property-read User $user
 * @property-read DatabaseConnection $databaseConnection
 * @property-read Collection<int, DatabaseConnection> $databaseConnections
 */
class QuerySession extends Model
{
    /** @use HasFactory<QuerySessionFactory> */
    use HasFactory;

    protected $fillable = [
        'query_request_id',
        'user_id',
        'database_connection_id',
        'started_at',
        'expires_at',
        'ended_at',
    ];

    /**
     * @return array{started_at: 'datetime', expires_at: 'datetime', ended_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<QueryRequest, $this>
     */
    public function queryRequest(): BelongsTo
    {
        return $this->belongsTo(QueryRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<DatabaseConnection, $this>
     */
    public function databaseConnection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class);
    }

    /**
     * @return BelongsToMany<DatabaseConnection, $this>
     */
    public function databaseConnections(): BelongsToMany
    {
        return $this->belongsToMany(DatabaseConnection::class, 'query_session_connections')
            ->withTimestamps();
    }

    /**
     * @return HasMany<QuerySessionQuery, $this>
     */
    public function queries(): HasMany
    {
        return $this->hasMany(QuerySessionQuery::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }
}
