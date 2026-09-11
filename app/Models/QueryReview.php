<?php

namespace App\Models;

use Database\Factories\QueryReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $query_request_id
 * @property int $query_request_revision
 * @property int $reviewer_id
 * @property string $decision
 * @property string|null $comment
 */
#[Fillable(['query_request_id', 'query_request_revision', 'reviewer_id', 'decision', 'comment'])]
class QueryReview extends Model
{
    /** @use HasFactory<QueryReviewFactory> */
    use HasFactory;

    /**
     * @return array{query_request_revision: 'integer'}
     */
    protected function casts(): array
    {
        return [
            'query_request_revision' => 'integer',
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
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
