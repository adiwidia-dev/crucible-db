<?php

namespace App\Http\Requests;

use App\Models\NativeProxyDeviceAuthorization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveNativeProxyDeviceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $authorization = $this->route('device_authorization');

        return $authorization instanceof NativeProxyDeviceAuthorization
            && ($this->user()?->can('manageNativeProxy', $authorization->lease->querySession) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'deny'])],
        ];
    }
}
