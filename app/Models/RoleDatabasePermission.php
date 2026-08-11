<?php

namespace App\Models;

use App\Enums\AccessMode;
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
 * @property-read Role $role
 * @property-read DatabaseConnection $databaseConnection
 */
#[Fillable(['role_id', 'database_connection_id', 'access_mode', 'can_review', 'requires_approval'])]
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
}
