<?php

namespace App\Http\Requests;

use App\Enums\AuthProviderType;
use App\Models\AuthProvider;
use App\Rules\OidcIssuerUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAuthProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $provider = AuthProviderType::tryFrom($this->string('provider')->toString());
        $providerRules = ['required', Rule::enum(AuthProviderType::class)];

        if ($provider !== AuthProviderType::OpenIdConnect) {
            $providerRules[] = Rule::unique((new AuthProvider)->getTable(), 'provider');
        }

        return [
            'provider' => $providerRules,
            'name' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'string', 'max:1000'],
            'client_secret' => ['required', 'string', 'max:4000'],
            'scopes' => ['nullable', 'string', 'max:2000'],
            'allowed_domains' => ['nullable', 'string', 'max:2000'],
            'tenant' => ['nullable', 'string', 'max:255'],
            'issuer_url' => [
                Rule::requiredIf($provider === AuthProviderType::OpenIdConnect),
                'nullable',
                'string',
                'max:2048',
                new OidcIssuerUrl,
            ],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{provider: string, name: string, client_id: string, client_secret: string, scopes: array<int, string>|null, allowed_domains: array<int, string>|null, tenant: string|null, issuer_url: string|null, is_enabled: bool}
     */
    public function providerAttributes(): array
    {
        $validated = $this->validated();
        $provider = AuthProviderType::from($validated['provider']);

        return [
            'provider' => $validated['provider'],
            'name' => $validated['name'],
            'client_id' => $validated['client_id'],
            'client_secret' => $validated['client_secret'],
            'scopes' => $this->stringList($validated['scopes'] ?? null),
            'allowed_domains' => $this->domainList($validated['allowed_domains'] ?? null),
            'tenant' => $provider === AuthProviderType::Microsoft ? ($validated['tenant'] ?? null) : null,
            'issuer_url' => $provider === AuthProviderType::OpenIdConnect
                ? rtrim(trim((string) $validated['issuer_url']), '/')
                : null,
            'is_enabled' => (bool) ($validated['is_enabled'] ?? false),
        ];
    }

    /**
     * @return array<int, string>|null
     */
    private function stringList(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return collect(preg_split('/[\s,]+/', $value) ?: [])
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>|null
     */
    private function domainList(?string $value): ?array
    {
        return collect($this->stringList($value))
            ->map(fn (string $domain): string => strtolower(ltrim($domain, '@')))
            ->filter()
            ->unique()
            ->values()
            ->all() ?: null;
    }
}
