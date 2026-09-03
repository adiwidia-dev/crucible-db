<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportNativeProxyConnectionTrafficRequest extends FormRequest
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
            'proxy_connection_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'bytes_received' => ['required', 'integer', 'min:0', 'max:1099511627776'],
            'bytes_sent' => ['required', 'integer', 'min:0', 'max:1099511627776'],
        ];
    }
}
