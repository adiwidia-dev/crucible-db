<?php

namespace App\Models;

use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use Database\Factories\QueryRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $requester_id
 * @property int $database_connection_id
 * @property int|null $approved_by_id
 * @property int|null $dispatched_by_id
 * @property string $title
 * @property string|null $description
 * @property string $sql
 * @property QueryType $query_type
 * @property QueryRequestKind $request_kind
 * @property QueryRequestStatus $status
 * @property bool $requires_approval
 * @property Carbon|null $scheduled_at
 * @property int|null $access_duration_minutes
 * @property Carbon|null $approved_at
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $completed_at
 * @property array<string, mixed>|null $result_summary
 * @property string|null $last_error
 * @property-read bool $has_write_execution
 * @property-read User $requester
 * @property-read User|null $approvedBy
 * @property-read User|null $dispatchedBy
 * @property-read DatabaseConnection $databaseConnection
 * @property-read QueryExecution|null $latestExecution
 * @property-read QuerySession|null $latestSession
 */
#[Fillable(['requester_id', 'database_connection_id', 'approved_by_id', 'dispatched_by_id', 'title', 'description', 'sql', 'query_type', 'request_kind', 'status', 'requires_approval', 'scheduled_at', 'access_duration_minutes', 'approved_at', 'dispatched_at', 'completed_at', 'result_summary', 'last_error'])]
class QueryRequest extends Model
{
    /** @use HasFactory<QueryRequestFactory> */
    use HasFactory;

    /**
     * @return array{query_type: class-string<QueryType>, request_kind: class-string<QueryRequestKind>, status: class-string<QueryRequestStatus>, requires_approval: 'boolean', scheduled_at: 'datetime', access_duration_minutes: 'integer', approved_at: 'datetime', dispatched_at: 'datetime', completed_at: 'datetime', result_summary: 'array'}
     */
    protected function casts(): array
    {
        return [
            'query_type' => QueryType::class,
            'request_kind' => QueryRequestKind::class,
            'status' => QueryRequestStatus::class,
            'requires_approval' => 'boolean',
            'scheduled_at' => 'datetime',
            'access_duration_minutes' => 'integer',
            'approved_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
            'result_summary' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by_id');
    }

    /**
     * @return BelongsTo<DatabaseConnection, $this>
     */
    public function databaseConnection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class);
    }

    /**
     * @return HasMany<QueryReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(QueryReview::class);
    }

    /**
     * @return HasMany<QueryExecution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(QueryExecution::class);
    }

    /**
     * @return HasOne<QueryExecution, $this>
     */
    public function latestExecution(): HasOne
    {
        return $this->hasOne(QueryExecution::class)->latestOfMany('started_at');
    }

    /**
     * @return HasMany<QuerySession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(QuerySession::class);
    }

    /**
     * @return HasOne<QuerySession, $this>
     */
    public function latestSession(): HasOne
    {
        return $this->hasOne(QuerySession::class)->latestOfMany('started_at');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            QueryRequestStatus::Completed,
            QueryRequestStatus::Failed,
            QueryRequestStatus::Rejected,
            QueryRequestStatus::Cancelled,
        ], true);
    }
}
