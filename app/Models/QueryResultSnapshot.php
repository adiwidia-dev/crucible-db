<?php

namespace App\Models;

use Database\Factories\QueryResultSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueryResultSnapshot extends Model
{
    /** @use HasFactory<QueryResultSnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'query_session_query_id',
        'rows',
        'row_count',
        'byte_count',
        'expires_at',
    ];

    /**
     * @return array{rows: 'encrypted:array', row_count: 'integer', byte_count: 'integer', expires_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'rows' => 'encrypted:array',
            'row_count' => 'integer',
            'byte_count' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<QuerySessionQuery, $this>
     */
    public function querySessionQuery(): BelongsTo
    {
        return $this->belongsTo(QuerySessionQuery::class);
    }
}
