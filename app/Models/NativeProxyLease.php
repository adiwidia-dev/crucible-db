<?php

namespace App\Models;

use App\Enums\AccessMode;
use App\Enums\DatabaseDriver;
use App\Enums\NativeProxyLeaseStatus;
use Database\Factories\NativeProxyLeaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $query_session_id
 * @property int $query_request_id
 * @property int $user_id
 * @property int $database_connection_id
 * @property DatabaseDriver $protocol
 * @property AccessMode $access_mode
 * @property string $synthetic_username
 * @property string|null $synthetic_password_hash
 * @property string|null $protocol_auth_secret
 * @property string|null $credential_creation_idempotency_key
 * @property int $credential_version
 * @property NativeProxyLeaseStatus $status
 * @property int $max_concurrent_connections
 * @property Carbon|null $credentials_revealed_at
 * @property Carbon|null $activated_at
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property string|null $revocation_reason
 * @property int|null $revoked_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['query_session_id', 'query_request_id', 'user_id', 'database_connection_id', 'protocol', 'access_mode', 'synthetic_username', 'synthetic_password_hash', 'protocol_auth_secret', 'credential_creation_idempotency_key', 'credential_version', 'status', 'max_concurrent_connections', 'credentials_revealed_at', 'activated_at', 'expires_at', 'last_used_at', 'revoked_at', 'revocation_reason', 'revoked_by_id'])]
#[Hidden(['synthetic_password_hash', 'protocol_auth_secret', 'credential_creation_idempotency_key'])]
class NativeProxyLease extends Model
{
    /** @use HasFactory<NativeProxyLeaseFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'protocol' => DatabaseDriver::class,
            'access_mode' => AccessMode::class,
            'status' => NativeProxyLeaseStatus::class,
            'protocol_auth_secret' => 'encrypted',
            'credential_version' => 'integer',
            'max_concurrent_connections' => 'integer',
            'credentials_revealed_at' => 'datetime',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', NativeProxyLeaseStatus::Active)->where('expires_at', '>', now());
    }

    /** @return BelongsTo<QuerySession, $this> */
    public function querySession(): BelongsTo
    {
        return $this->belongsTo(QuerySession::class);
    }

    /** @return BelongsTo<QueryRequest, $this> */
    public function queryRequest(): BelongsTo
    {
        return $this->belongsTo(QueryRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<DatabaseConnection, $this> */
    public function databaseConnection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class);
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_id');
    }

    /** @return HasMany<NativeProxyDeviceAuthorization, $this> */
    public function deviceAuthorizations(): HasMany
    {
        return $this->hasMany(NativeProxyDeviceAuthorization::class, 'lease_id');
    }

    /** @return HasMany<NativeProxyToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(NativeProxyToken::class, 'lease_id');
    }

    /** @return HasMany<NativeProxyAuthAttempt, $this> */
    public function authAttempts(): HasMany
    {
        return $this->hasMany(NativeProxyAuthAttempt::class, 'lease_id');
    }

    /** @return HasMany<NativeProxyConnection, $this> */
    public function connections(): HasMany
    {
        return $this->hasMany(NativeProxyConnection::class, 'lease_id');
    }
}
