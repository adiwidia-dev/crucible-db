<?php

namespace App\Models;

use App\Enums\ExecutionStatus;
use App\Enums\QueryType;
use Database\Factories\QueryExecutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $query_request_id
 * @property int|null $executed_by_id
 * @property string|null $sql
 * @property QueryType|null $query_type
 * @property ExecutionStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property int|null $row_count
 * @property bool $result_truncated
 * @property array<int, array<string, mixed>>|null $sample_rows
 * @property string|null $error_message
 * @property-read User|null $executor
 */
#[Fillable(['query_request_id', 'executed_by_id', 'sql', 'query_type', 'status', 'started_at', 'finished_at', 'duration_ms', 'row_count', 'result_truncated', 'sample_rows', 'error_message'])]
class QueryExecution extends Model
{
    /** @use HasFactory<QueryExecutionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ExecutionStatus::class,
            'query_type' => QueryType::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'row_count' => 'integer',
            'result_truncated' => 'boolean',
            'sample_rows' => 'array',
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
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_id');
    }
}
