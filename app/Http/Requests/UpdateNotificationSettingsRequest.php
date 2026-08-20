<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateNotificationSettingsRequest extends FormRequest
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
            'notifications_in_app_enabled' => ['required', 'boolean'],
            'notifications_email_enabled' => ['required', 'boolean'],
            'notifications_review_enabled' => ['required', 'boolean'],
            'notifications_execution_completed_enabled' => ['required', 'boolean'],
            'notifications_execution_failed_enabled' => ['required', 'boolean'],
            'notifications_query_access_enabled' => ['required', 'boolean'],
            'notifications_connection_failed_enabled' => ['required', 'boolean'],
            'operational_recipient_ids' => ['nullable', 'array'],
            'operational_recipient_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $recipientIds = $this->validated('operational_recipient_ids', []);

            if ($recipientIds === []) {
                return;
            }

            $adminCount = User::query()
                ->whereKey($recipientIds)
                ->whereHas('roles', fn ($roles) => $roles->where('is_admin', true))
                ->count();

            if ($adminCount !== count($recipientIds)) {
                $validator->errors()->add('operational_recipient_ids', 'Operational alert recipients must have an administrator role.');
            }
        }];
    }
}
