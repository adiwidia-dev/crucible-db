<?php

namespace App\Models;

use App\Enums\AccessMode;
use App\Enums\QueryType;
use Database\Factories\RoleDatabasePermissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $role_id
 * @property int $database_connection_id
 * @property AccessMode $access_mode
 * @property bool $can_review
 * @property bool $requires_approval
 * @property bool $read_requires_approval
 * @property bool $write_requires_approval
 * @property int|null $max_write_session_minutes
 * @property-read Role $role
 * @property-read DatabaseConnection $databaseConnection
 */
#[Fillable(['role_id', 'database_connection_id', 'access_mode', 'can_review', 'requires_approval', 'read_requires_approval', 'write_requires_approval', 'max_write_session_minutes'])]
class RoleDatabasePermission extends Model
{
    /** @use HasFactory<RoleDatabasePermissionFactory> */
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
     * @return BelongsTo<DatabaseConnection, $this>
     */
    public function databaseConnection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class);
    }

    public function requiresApprovalFor(QueryType $queryType): bool
    {
        return $queryType === QueryType::Read
            ? $this->read_requires_approval
            : $this->write_requires_approval;
    }

    protected static function booted(): void
    {
        static::saving(function (self $permission): void {
            if (
                $permission->isDirty('requires_approval')
                && ! $permission->isDirty('read_requires_approval')
                && ! $permission->isDirty('write_requires_approval')
            ) {
                $permission->read_requires_approval = $permission->requires_approval;
                $permission->write_requires_approval = $permission->requires_approval;
            }
        });
    }
}
