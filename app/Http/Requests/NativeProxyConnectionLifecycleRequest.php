<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NativeProxyConnectionLifecycleRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:512'],
        ];
    }
}
