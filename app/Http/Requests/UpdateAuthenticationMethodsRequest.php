<?php

namespace App\Http\Requests;

use App\Models\AuthProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAuthenticationMethodsRequest extends FormRequest
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
            'password_login_enabled' => ['required', 'boolean'],
            'passkey_login_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->boolean('password_login_enabled') || $this->boolean('passkey_login_enabled')) {
                return;
            }

            if (AuthProvider::query()->where('is_enabled', true)->exists()) {
                return;
            }

            $validator->errors()->add('password_login_enabled', 'Keep at least one authentication method enabled.');
        }];
    }
}
