<?php

namespace App\Models;

use App\Enums\ExecutionStatus;
use App\Enums\QueryType;
use Database\Factories\QuerySessionQueryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $query_session_id
 * @property int $user_id
 * @property string $sql
 * @property QueryType $query_type
 * @property ExecutionStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property int|null $row_count
 * @property bool $result_truncated
 * @property array<int, array<string, mixed>>|null $sample_rows
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read QuerySession $querySession
 * @property-read User $user
 */
class QuerySessionQuery extends Model
{
    /** @use HasFactory<QuerySessionQueryFactory> */
    use HasFactory;

    protected $fillable = [
        'query_session_id',
        'user_id',
        'sql',
        'query_type',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'row_count',
        'result_truncated',
        'sample_rows',
        'error_message',
    ];

    /**
     * @return array{query_type: class-string<QueryType>, status: class-string<ExecutionStatus>, started_at: 'datetime', finished_at: 'datetime', duration_ms: 'integer', row_count: 'integer', result_truncated: 'boolean', sample_rows: 'array'}
     */
    protected function casts(): array
    {
        return [
            'query_type' => QueryType::class,
            'status' => ExecutionStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'row_count' => 'integer',
            'result_truncated' => 'boolean',
            'sample_rows' => 'array',
        ];
    }

    /**
     * @return BelongsTo<QuerySession, $this>
     */
    public function querySession(): BelongsTo
    {
        return $this->belongsTo(QuerySession::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
