<?php

namespace App\Models;

use App\Enums\DatabaseDriver;
use App\Enums\DatabaseTlsMode;
use App\Enums\NativeProxyConnectionStatus;
use Database\Factories\NativeProxyConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['proxy_connection_id', 'lease_id', 'query_session_id', 'query_request_id', 'user_id', 'database_connection_id', 'protocol', 'proxy_instance_id', 'client_application', 'client_version', 'cli_version', 'operating_system', 'architecture', 'upstream_tls_mode', 'upstream_tls_verified', 'status', 'reservation_expires_at', 'connected_at', 'authenticated_at', 'last_activity_at', 'disconnected_at', 'disconnect_reason', 'bytes_received', 'bytes_sent', 'statement_count'])]
class NativeProxyConnection extends Model
{
    /** @use HasFactory<NativeProxyConnectionFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'protocol' => DatabaseDriver::class, 'upstream_tls_mode' => DatabaseTlsMode::class,
            'upstream_tls_verified' => 'boolean', 'status' => NativeProxyConnectionStatus::class,
            'reservation_expires_at' => 'datetime', 'connected_at' => 'datetime', 'authenticated_at' => 'datetime',
            'last_activity_at' => 'datetime', 'disconnected_at' => 'datetime', 'bytes_received' => 'integer',
            'bytes_sent' => 'integer', 'statement_count' => 'integer',
        ];
    }

    /** @param Builder<self> $query @return Builder<self> */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', NativeProxyConnectionStatus::Active)->whereNull('disconnected_at');
    }

    /** @return BelongsTo<NativeProxyLease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(NativeProxyLease::class, 'lease_id');
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
}
