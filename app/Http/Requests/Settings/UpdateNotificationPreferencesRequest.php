<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email_approvals' => ['required', 'boolean'],
            'email_execution_completed' => ['required', 'boolean'],
            'email_execution_failed' => ['required', 'boolean'],
            'email_sessions' => ['required', 'boolean'],
            'email_connection_failed' => ['required', 'boolean'],
        ];
    }
}
