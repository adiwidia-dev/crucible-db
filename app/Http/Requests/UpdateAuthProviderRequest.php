<?php

namespace App\Http\Requests;

use App\Enums\AuthProviderType;
use App\Models\AuthProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAuthProviderRequest extends FormRequest
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
        /** @var AuthProvider $authProvider */
        $authProvider = $this->route('auth_provider');

        return [
            'provider' => [
                'required',
                Rule::enum(AuthProviderType::class),
                Rule::unique((new AuthProvider)->getTable(), 'provider')->ignore($authProvider->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'string', 'max:1000'],
            'client_secret' => ['nullable', 'string', 'max:4000'],
            'scopes' => ['nullable', 'string', 'max:2000'],
            'allowed_domains' => ['nullable', 'string', 'max:2000'],
            'tenant' => ['nullable', 'string', 'max:255'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function providerAttributes(): array
    {
        $validated = $this->validated();

        $attributes = [
            'provider' => $validated['provider'],
            'name' => $validated['name'],
            'client_id' => $validated['client_id'],
            'scopes' => $this->stringList($validated['scopes'] ?? null),
            'allowed_domains' => $this->domainList($validated['allowed_domains'] ?? null),
            'tenant' => $validated['tenant'] ?? null,
            'is_enabled' => (bool) ($validated['is_enabled'] ?? false),
        ];

        if (($validated['client_secret'] ?? '') !== '') {
            $attributes['client_secret'] = $validated['client_secret'];
        }

        return $attributes;
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
