<?php

namespace App\Http\Requests;

use App\Models\NativeProxyLease;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RotateNativeProxyCredentialsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $lease = $this->route('native_proxy_lease');

        return $lease instanceof NativeProxyLease
            && ($this->user()?->can('manageNativeProxy', $lease->querySession) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
        ];
    }
}
