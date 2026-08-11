<?php

namespace App\Http\Requests;

use App\Enums\QueryRequestKind;
use App\Models\QueryRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQueryRequestRequest extends FormRequest
{
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
            'database_connection_id' => ['required', 'exists:database_connections,id'],
            'request_kind' => ['required', Rule::enum(QueryRequestKind::class)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sql' => ['nullable', 'required_if:request_kind,'.QueryRequestKind::SingleExecution->value, 'string', 'max:20000'],
            'schedule_query' => ['nullable', 'boolean'],
            'scheduled_at' => ['nullable', 'required_if:schedule_query,1', 'date', 'after:now'],
            'access_duration_minutes' => ['nullable', 'required_if:request_kind,'.QueryRequestKind::QueryAccess->value, 'integer', 'min:5', 'max:1440'],
        ];
    }
}
