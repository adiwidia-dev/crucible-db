<?php

namespace App\Http\Requests;

use App\Models\NativeProxyLease;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StartNativeProxyDeviceAuthorizationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lease_id' => ['required', 'string', 'size:26', 'exists:'.NativeProxyLease::class.',id'],
            'cli_version' => ['required', 'string', 'max:64'],
            'operating_system' => ['required', 'string', 'max:64'],
            'architecture' => ['required', 'string', 'max:64'],
            'device_label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
