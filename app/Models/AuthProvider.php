<?php

namespace App\Models;

use App\Enums\AuthProviderType;
use Database\Factories\AuthProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property AuthProviderType $provider
 * @property string $name
 * @property string $client_id
 * @property string $client_secret
 * @property array<int, string>|null $scopes
 * @property array<int, string>|null $allowed_domains
 * @property string|null $tenant
 * @property bool $is_enabled
 */
#[Fillable(['provider', 'name', 'client_id', 'client_secret', 'scopes', 'allowed_domains', 'tenant', 'is_enabled'])]
#[Hidden(['client_secret'])]
class AuthProvider extends Model
{
    /** @use HasFactory<AuthProviderFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_enabled' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => AuthProviderType::class,
            'client_secret' => 'encrypted',
            'scopes' => 'array',
            'allowed_domains' => 'array',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return HasMany<UserIdentity, $this>
     */
    public function userIdentities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    /**
     * @return array<int, string>
     */
    public function effectiveScopes(): array
    {
        return $this->scopes ?: $this->provider->defaultScopes();
    }

    public function callbackUrl(): string
    {
        return route('auth-providers.callback', $this);
    }

    /**
     * @return array{id: int, provider: string, name: string, redirect_url: string}
     */
    public function authPayload(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider->value,
            'name' => $this->name,
            'redirect_url' => route('auth-providers.redirect', $this),
        ];
    }
}
