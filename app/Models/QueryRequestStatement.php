<?php

namespace App\Models;

use App\Enums\QueryType;
use Database\Factories\QueryRequestStatementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $query_request_id
 * @property int $position
 * @property string $sql
 * @property QueryType $query_type
 * @property-read QueryRequest $queryRequest
 */
#[Fillable(['query_request_id', 'position', 'sql', 'query_type'])]
class QueryRequestStatement extends Model
{
    /** @use HasFactory<QueryRequestStatementFactory> */
    use HasFactory;

    /**
     * @return array{query_type: class-string<QueryType>, position: 'integer'}
     */
    protected function casts(): array
    {
        return [
            'query_type' => QueryType::class,
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<QueryRequest, $this>
     */
    public function queryRequest(): BelongsTo
    {
        return $this->belongsTo(QueryRequest::class);
    }
}
