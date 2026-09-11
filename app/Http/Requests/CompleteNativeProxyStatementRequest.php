<?php

namespace App\Http\Requests;

use App\Services\NativeProxy\NativeStatementOutcome;
use Illuminate\Foundation\Http\FormRequest;

class CompleteNativeProxyStatementRequest extends FormRequest
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
            'succeeded' => ['required', 'boolean'],
            'row_count' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'error_message' => ['nullable', 'string', 'max:512'],
        ];
    }

    public function toOutcome(): NativeStatementOutcome
    {
        return new NativeStatementOutcome(
            $this->boolean('succeeded'),
            $this->validated('row_count'),
            $this->validated('error_message'),
            $this->validated('duration_ms'),
        );
    }
}
