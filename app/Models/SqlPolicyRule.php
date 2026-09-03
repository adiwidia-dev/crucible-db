<?php

namespace App\Models;

use App\Enums\DatabaseDriver;
use App\Enums\QueryType;
use App\Enums\SqlPolicyRuleEffect;
use App\Enums\SqlPolicyRuleMatchType;
use App\Enums\SqlPolicyRuleScope;
use Database\Factories\SqlPolicyRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property DatabaseDriver $database_driver
 * @property SqlPolicyRuleEffect $effect
 * @property SqlPolicyRuleMatchType $match_type
 * @property string $match_value
 * @property string|null $canonical_sql
 * @property string|null $shape_signature
 * @property string|null $shape_label
 * @property SqlPolicyRuleScope $scope_type
 * @property int|null $scope_id
 * @property string $scope_key
 * @property bool $is_enabled
 * @property Carbon|null $created_at
 * @property-read User $createdBy
 */
#[Fillable(['database_driver', 'effect', 'match_type', 'match_value', 'canonical_sql', 'shape_signature', 'shape_label', 'scope_type', 'scope_id', 'scope_key', 'created_by_id', 'source_candidate_id', 'is_enabled'])]

class SqlPolicyRule extends Model
{
    /** @use HasFactory<SqlPolicyRuleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'database_driver' => DatabaseDriver::class,
            'effect' => SqlPolicyRuleEffect::class,
            'match_type' => SqlPolicyRuleMatchType::class,
            'scope_type' => SqlPolicyRuleScope::class,
            'is_enabled' => 'boolean',
        ];
    }

    public function queryType(): QueryType
    {
        return QueryType::Write;
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsTo<SqlPolicyCandidate, $this> */
    public function sourceCandidate(): BelongsTo
    {
        return $this->belongsTo(SqlPolicyCandidate::class, 'source_candidate_id');
    }
}
