<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AuthenticateNativeProxyConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'proxy_instance_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'client_application' => ['nullable', 'string', 'max:128'],
            'client_version' => ['nullable', 'string', 'max:128'],
        ];
    }

    /** @return array{client_application:string|null, client_version:string|null} */
    public function clientMetadata(): array
    {
        return [
            'client_application' => $this->validated('client_application'),
            'client_version' => $this->validated('client_version'),
        ];
    }
}
