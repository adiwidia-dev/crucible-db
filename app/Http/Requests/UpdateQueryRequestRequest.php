<?php

namespace App\Http\Requests;

use App\Enums\QueryRequestKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQueryRequestRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('request_kind') === QueryRequestKind::SingleExecution->value
            && ! $this->has('statements')
            && $this->filled('sql')) {
            $this->merge([
                'statements' => [['sql' => $this->input('sql')]],
            ]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('query_request')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'database_connection_id' => ['required', 'exists:database_connections,id'],
            'request_kind' => ['required', Rule::enum(QueryRequestKind::class)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'statements' => ['nullable', 'required_if:request_kind,'.QueryRequestKind::SingleExecution->value, 'array', 'min:1', 'max:50'],
            'statements.*.sql' => ['required', 'string', 'max:20000'],
            'sql' => ['nullable', 'string', 'max:20000'],
            'schedule_query' => ['nullable', 'boolean'],
            'scheduled_at' => ['nullable', 'required_if:schedule_query,1', 'date', 'after:now'],
            'access_duration_minutes' => ['nullable', 'required_if:request_kind,'.QueryRequestKind::QueryAccess->value, 'integer', 'min:5', 'max:1440'],
        ];
    }
}
