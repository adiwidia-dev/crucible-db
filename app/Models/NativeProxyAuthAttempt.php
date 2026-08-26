<?php

namespace App\Models;

use App\Enums\DatabaseDriver;
use Database\Factories\NativeProxyAuthAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lease_id', 'token_id', 'device_authorization_id', 'proxy_instance_id', 'protocol', 'credential_version', 'proxy_connection_id', 'status', 'expires_at', 'consumed_at'])]
#[Hidden(['proxy_connection_id'])]
class NativeProxyAuthAttempt extends Model
{
    /** @use HasFactory<NativeProxyAuthAttemptFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return ['protocol' => DatabaseDriver::class, 'credential_version' => 'integer', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    /** @return BelongsTo<NativeProxyLease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(NativeProxyLease::class, 'lease_id');
    }

    /** @return BelongsTo<NativeProxyToken, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(NativeProxyToken::class, 'token_id');
    }

    /** @return BelongsTo<NativeProxyDeviceAuthorization, $this> */
    public function deviceAuthorization(): BelongsTo
    {
        return $this->belongsTo(NativeProxyDeviceAuthorization::class);
    }
}
