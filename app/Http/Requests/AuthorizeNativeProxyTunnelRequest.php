<?php

namespace App\Http\Requests;

use App\Enums\DatabaseDriver;
use App\Services\NativeProxy\TunnelAuthorizationData;
use Illuminate\Foundation\Http\FormRequest;

class AuthorizeNativeProxyTunnelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'lease_id' => ['required', 'string', 'size:26', 'exists:native_proxy_leases,id'],
            'device_authorization_id' => ['required', 'string', 'size:26', 'exists:native_proxy_device_authorizations,id'],
            'proxy_connection_id' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'proxy_instance_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'protocol' => ['required', 'string', 'in:mysql,postgresql'],
            'bearer_hash' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/i'],
        ];
    }

    public function toAuthorizationData(): TunnelAuthorizationData
    {
        return new TunnelAuthorizationData(
            $this->validated('lease_id'),
            $this->validated('device_authorization_id'),
            $this->validated('proxy_connection_id'),
            $this->validated('proxy_instance_id'),
            DatabaseDriver::fromNativeProxyProtocol($this->validated('protocol')),
            strtolower($this->validated('bearer_hash')),
        );
    }
}
