<?php

namespace App\Http\Requests;

use App\Enums\QueryRequestKind;
use App\Models\QueryRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQueryRequestRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('request_kind') === QueryRequestKind::QueryAccess->value
            && ! $this->has('database_connection_ids')
            && $this->filled('database_connection_id')) {
            $this->merge([
                'database_connection_ids' => [$this->input('database_connection_id')],
            ]);
        }

        if ($this->input('request_kind') !== QueryRequestKind::SingleExecution->value) {
            return;
        }

        if (! $this->has('statements') && $this->filled('sql')) {
            $this->merge([
                'statements' => [[
                    'sql' => $this->input('sql'),
                    'database_connection_id' => $this->input('database_connection_id'),
                ]],
            ]);
        }

        $statements = $this->input('statements', []);

        if (! is_array($statements)) {
            return;
        }

        $this->merge([
            'statements' => array_map(
                fn (mixed $statement): mixed => is_array($statement)
                    ? [
                        ...$statement,
                        'database_connection_id' => $statement['database_connection_id'] ?? $this->input('database_connection_id'),
                    ]
                    : $statement,
                $statements,
            ),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', QueryRequest::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'database_connection_id' => ['nullable', 'integer', 'exists:database_connections,id'],
            'database_connection_ids' => ['nullable', 'required_if:request_kind,'.QueryRequestKind::QueryAccess->value, 'array', 'min:1', 'max:10'],
            'database_connection_ids.*' => ['required', 'integer', 'distinct', 'exists:database_connections,id'],
            'request_kind' => ['required', Rule::enum(QueryRequestKind::class)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'statements' => ['nullable', 'required_if:request_kind,'.QueryRequestKind::SingleExecution->value, 'array', 'min:1', 'max:50'],
            'statements.*.sql' => ['required', 'string', 'max:20000'],
            'statements.*.database_connection_id' => ['required_if:request_kind,'.QueryRequestKind::SingleExecution->value, 'integer', 'exists:database_connections,id'],
            'sql' => ['nullable', 'string', 'max:20000'],
            'schedule_query' => ['nullable', 'boolean'],
            'scheduled_at' => ['nullable', 'required_if:schedule_query,1', 'date', 'after:now'],
            'access_duration_minutes' => ['nullable', 'required_if:request_kind,'.QueryRequestKind::QueryAccess->value, 'integer', 'min:5', 'max:1440'],
        ];
    }
}
