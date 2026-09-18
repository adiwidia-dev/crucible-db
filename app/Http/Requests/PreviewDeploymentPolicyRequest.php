<?php

namespace App\Http\Requests;

use App\Models\QueryRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PreviewDeploymentPolicyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', QueryRequest::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'statements' => ['required', 'array', 'min:1', 'max:50'],
            'statements.*.sql' => ['required', 'string', 'max:1000000'],
            'statements.*.database_connection_id' => ['required', 'integer', 'exists:database_connections,id'],
        ];
    }
}
