<?php

namespace App\Models;

use App\Enums\DatabaseDriver;
use App\Enums\SqlPolicyCandidateResolution;
use Database\Factories\SqlPolicyCandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property DatabaseDriver $database_driver
 * @property string $exact_fingerprint
 * @property string $canonical_sql
 * @property string|null $shape_signature
 * @property string|null $shape_label
 * @property SqlPolicyCandidateResolution|null $resolution
 * @property int|null $resolved_by_id
 * @property int|null $resolved_rule_id
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property int $occurrences_count
 * @property-read Collection<int, SqlPolicyCandidateOccurrence> $occurrences
 * @property-read User|null $resolvedBy
 */
#[Fillable(['database_driver', 'exact_fingerprint', 'canonical_sql', 'shape_signature', 'shape_label', 'resolution', 'resolved_by_id', 'resolved_rule_id', 'first_seen_at', 'last_seen_at'])]

class SqlPolicyCandidate extends Model
{
    /** @use HasFactory<SqlPolicyCandidateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'database_driver' => DatabaseDriver::class,
            'resolution' => SqlPolicyCandidateResolution::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /** @return HasMany<SqlPolicyCandidateOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(SqlPolicyCandidateOccurrence::class);
    }
}
