<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSqlStatementPolicyRequest extends FormRequest
{
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
            'sql_read_queries_enabled' => ['required', 'boolean'],
            'sql_insert_enabled' => ['required', 'boolean'],
            'sql_update_enabled' => ['required', 'boolean'],
            'sql_delete_enabled' => ['required', 'boolean'],
            'sql_create_table_enabled' => ['required', 'boolean'],
            'sql_alter_table_enabled' => ['required', 'boolean'],
            'sql_drop_table_enabled' => ['required', 'boolean'],
            'sql_truncate_table_enabled' => ['required', 'boolean'],
        ];
    }
}
