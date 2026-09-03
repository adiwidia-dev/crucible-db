<?php

namespace App\Models;

use App\Enums\NativeProxyDeviceAuthorizationStatus;
use Database\Factories\NativeProxyDeviceAuthorizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['lease_id', 'device_code_hash', 'user_code_hash', 'cli_version', 'operating_system', 'architecture', 'device_label', 'polling_interval_seconds', 'poll_count', 'status', 'expires_at', 'last_polled_at', 'approved_at', 'authorized_by_id', 'denied_at', 'consumed_at'])]
#[Hidden(['device_code_hash', 'user_code_hash'])]
class NativeProxyDeviceAuthorization extends Model
{
    /** @use HasFactory<NativeProxyDeviceAuthorizationFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'status' => NativeProxyDeviceAuthorizationStatus::class,
            'polling_interval_seconds' => 'integer', 'poll_count' => 'integer',
            'expires_at' => 'datetime', 'last_polled_at' => 'datetime', 'approved_at' => 'datetime',
            'denied_at' => 'datetime', 'consumed_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query @return Builder<self> */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [NativeProxyDeviceAuthorizationStatus::Pending, NativeProxyDeviceAuthorizationStatus::Approved])->where('expires_at', '>', now());
    }

    /** @param Builder<self> $query @return Builder<self> */
    public function scopeWithActiveToken(Builder $query): Builder
    {
        return $query->whereHas('tokens', fn (Builder $tokens): Builder => $tokens->active());
    }

    /** @return BelongsTo<NativeProxyLease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(NativeProxyLease::class, 'lease_id');
    }

    /** @return BelongsTo<User, $this> */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_id');
    }

    /** @return HasMany<NativeProxyToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(NativeProxyToken::class, 'device_authorization_id');
    }

    /** @return HasMany<NativeProxyAuthAttempt, $this> */
    public function authAttempts(): HasMany
    {
        return $this->hasMany(NativeProxyAuthAttempt::class, 'device_authorization_id');
    }
}
