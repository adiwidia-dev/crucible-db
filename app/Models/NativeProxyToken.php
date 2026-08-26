<?php

namespace App\Models;

use Database\Factories\NativeProxyTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['lease_id', 'device_authorization_id', 'token_hash', 'scope', 'issued_at', 'expires_at', 'last_used_at', 'revoked_at', 'revocation_reason'])]
#[Hidden(['token_hash'])]
class NativeProxyToken extends Model
{
    /** @use HasFactory<NativeProxyTokenFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'expires_at' => 'datetime', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @param Builder<self> $query @return Builder<self> */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /** @return BelongsTo<NativeProxyLease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(NativeProxyLease::class, 'lease_id');
    }

    /** @return BelongsTo<NativeProxyDeviceAuthorization, $this> */
    public function deviceAuthorization(): BelongsTo
    {
        return $this->belongsTo(NativeProxyDeviceAuthorization::class);
    }

    /** @return HasMany<NativeProxyAuthAttempt, $this> */
    public function authAttempts(): HasMany
    {
        return $this->hasMany(NativeProxyAuthAttempt::class, 'token_id');
    }
}
