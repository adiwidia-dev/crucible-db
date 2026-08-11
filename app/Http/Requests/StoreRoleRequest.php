<?php

namespace App\Http\Requests;

use App\Enums\AccessMode;
use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'description' => ['nullable', 'string', 'max:1000'],
            'policies' => ['nullable', 'array'],
            'policies.*.database_connection_id' => ['required', 'integer', 'distinct', 'exists:database_connections,id'],
            'policies.*.access_mode' => ['required', Rule::enum(AccessMode::class)],
            'policies.*.can_review' => ['sometimes', 'boolean'],
            'policies.*.requires_approval' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{name: string, slug: string, description: string|null, is_admin: false}
     */
    public function roleAttributes(): array
    {
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'is_admin' => false,
        ];
    }

    /**
     * @return array<int, array{database_connection_id: int, access_mode: string, can_review: bool, requires_approval: bool}>
     */
    public function policyAttributes(): array
    {
        $policies = $this->validated('policies', []);
        $attributes = [];

        foreach ($policies as $policy) {
            $attributes[] = [
                'database_connection_id' => (int) $policy['database_connection_id'],
                'access_mode' => $policy['access_mode'],
                'can_review' => (bool) ($policy['can_review'] ?? false),
                'requires_approval' => (bool) ($policy['requires_approval'] ?? true),
            ];
        }

        return $attributes;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $slug = Str::slug($this->string('name')->toString());

                if ($slug !== '' && Role::query()->where('slug', $slug)->exists()) {
                    $validator->errors()->add('name', 'A role with this slug already exists.');
                }
            },
        ];
    }
}
