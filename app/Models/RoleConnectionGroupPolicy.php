<?php

namespace App\Models;

use App\Enums\AccessMode;
use App\Enums\QueryType;
use Database\Factories\RoleConnectionGroupPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $role_id
 * @property int $connection_group_id
 * @property AccessMode $access_mode
 * @property bool $can_review
 * @property bool $requires_approval
 * @property bool $read_requires_approval
 * @property bool $write_requires_approval
 * @property int|null $max_write_session_minutes
 */
#[Fillable(['role_id', 'connection_group_id', 'access_mode', 'can_review', 'requires_approval', 'read_requires_approval', 'write_requires_approval', 'max_write_session_minutes'])]
class RoleConnectionGroupPolicy extends Model
{
    /** @use HasFactory<RoleConnectionGroupPolicyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'access_mode' => AccessMode::class,
            'can_review' => 'boolean',
            'requires_approval' => 'boolean',
            'read_requires_approval' => 'boolean',
            'write_requires_approval' => 'boolean',
            'max_write_session_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<ConnectionGroup, $this>
     */
    public function connectionGroup(): BelongsTo
    {
        return $this->belongsTo(ConnectionGroup::class);
    }

    public function requiresApprovalFor(QueryType $queryType): bool
    {
        return $queryType === QueryType::Read
            ? $this->read_requires_approval
            : $this->write_requires_approval;
    }

    protected static function booted(): void
    {
        static::saving(function (self $policy): void {
            if (
                $policy->isDirty('requires_approval')
                && ! $policy->isDirty('read_requires_approval')
                && ! $policy->isDirty('write_requires_approval')
            ) {
                $policy->read_requires_approval = $policy->requires_approval;
                $policy->write_requires_approval = $policy->requires_approval;
            }
        });
    }
}
