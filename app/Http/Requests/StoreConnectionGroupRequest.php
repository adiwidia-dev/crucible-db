<?php

namespace App\Http\Requests;

use App\Models\ConnectionGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConnectionGroupRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', Rule::unique(ConnectionGroup::class, 'name')],
            'description' => ['nullable', 'string', 'max:1000'],
            'database_connection_ids' => ['nullable', 'array'],
            'database_connection_ids.*' => ['required', 'integer', 'distinct', 'exists:database_connections,id'],
        ];
    }

    /**
     * @return array{name: string, description: string|null}
     */
    public function groupAttributes(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'description' => $this->filled('description') ? $this->string('description')->toString() : null,
        ];
    }

    /**
     * @return array<int, int>
     */
    public function connectionIds(): array
    {
        $connectionIds = $this->validated('database_connection_ids', []);

        if (! is_array($connectionIds)) {
            return [];
        }

        return array_map(static fn (mixed $connectionId): int => (int) $connectionId, $connectionIds);
    }
}
