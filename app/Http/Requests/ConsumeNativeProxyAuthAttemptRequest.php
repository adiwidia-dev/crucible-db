<?php

namespace App\Http\Requests;

use App\Enums\DatabaseDriver;
use App\Services\NativeProxy\ConsumeAuthAttemptData;
use Illuminate\Foundation\Http\FormRequest;

class ConsumeNativeProxyAuthAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'auth_attempt_id' => ['required', 'string', 'size:26', 'exists:native_proxy_auth_attempts,id'],
            'proxy_instance_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'synthetic_username' => ['required', 'string', 'max:128'],
            'synthetic_password' => ['required', 'string', 'min:1', 'max:256'],
            'protocol' => ['required', 'string', 'in:mysql,postgresql'],
        ];
    }

    public function toAdmissionData(): ConsumeAuthAttemptData
    {
        return new ConsumeAuthAttemptData(
            $this->validated('auth_attempt_id'),
            $this->validated('proxy_instance_id'),
            $this->validated('synthetic_username'),
            $this->validated('synthetic_password'),
            DatabaseDriver::fromNativeProxyProtocol($this->validated('protocol')),
        );
    }
}
