<?php

namespace App\Models;

use Database\Factories\SqlPolicyCandidateOccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sql_policy_candidate_id
 * @property int $query_request_id
 * @property int|null $query_request_statement_id
 * @property int|null $database_connection_id
 * @property-read QueryRequest|null $queryRequest
 * @property-read DatabaseConnection|null $databaseConnection
 */
#[Fillable(['sql_policy_candidate_id', 'query_request_id', 'query_request_statement_id', 'database_connection_id', 'last_seen_at'])]

class SqlPolicyCandidateOccurrence extends Model
{
    /** @use HasFactory<SqlPolicyCandidateOccurrenceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<SqlPolicyCandidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(SqlPolicyCandidate::class, 'sql_policy_candidate_id');
    }

    /** @return BelongsTo<QueryRequest, $this> */
    public function queryRequest(): BelongsTo
    {
        return $this->belongsTo(QueryRequest::class);
    }

    /** @return BelongsTo<QueryRequestStatement, $this> */
    public function queryRequestStatement(): BelongsTo
    {
        return $this->belongsTo(QueryRequestStatement::class);
    }

    /** @return BelongsTo<DatabaseConnection, $this> */
    public function databaseConnection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class);
    }
}
