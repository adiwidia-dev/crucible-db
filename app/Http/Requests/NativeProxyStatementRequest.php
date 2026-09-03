<?php

namespace App\Http\Requests;

use App\Services\NativeProxy\NativeStatementData;
use Illuminate\Foundation\Http\FormRequest;

class NativeProxyStatementRequest extends FormRequest
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
            'sql' => ['required', 'string', 'max:1048576'],
            'protocol_command' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/'],
            'parameter_count' => ['nullable', 'integer', 'min:0', 'max:32767'],
        ];
    }

    public function toStatementData(): NativeStatementData
    {
        return new NativeStatementData(
            $this->validated('sql'),
            $this->validated('protocol_command'),
            $this->integer('parameter_count'),
        );
    }
}
