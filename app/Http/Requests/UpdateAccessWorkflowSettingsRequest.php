<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccessWorkflowSettingsRequest extends FormRequest
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
            'query_access_enabled' => ['required', 'boolean'],
            'native_client_access_enabled' => ['required', 'boolean'],
            'native_proxy_username_prefix' => [
                'required',
                'string',
                'min:2',
                'max:24',
                'regex:/\A[a-z][a-z0-9_-]*\z/',
            ],
        ];
    }

    /**
     * Get the validation error messages for the request.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'native_proxy_username_prefix.regex' => 'The username prefix must start with a lowercase letter and contain only lowercase letters, numbers, underscores, or hyphens.',
        ];
    }
}
