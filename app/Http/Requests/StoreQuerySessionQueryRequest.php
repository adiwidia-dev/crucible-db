<?php

namespace App\Http\Requests;

use App\Models\QuerySession;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreQuerySessionQueryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $querySession = $this->route('query_session');

        if (! $this->filled('database_connection_id') && $querySession instanceof QuerySession) {
            $this->merge([
                'database_connection_id' => $querySession->database_connection_id,
            ]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $querySession = $this->route('query_session');

        return $querySession instanceof QuerySession
            && ($this->user()?->can('submitQuery', $querySession) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'database_connection_id' => ['required', 'integer', 'exists:database_connections,id'],
            'sql' => ['required', 'string', 'max:20000'],
        ];
    }
}
