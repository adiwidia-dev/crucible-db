<?php

namespace App\Models;

use App\Enums\ExecutionStatus;
use App\Enums\QueryType;
use Database\Factories\QuerySessionQueryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function querySession(): BelongsTo
    {
        return $this->belongsTo(QuerySession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
