<?php

namespace App\Http\Requests;

use App\Enums\FailureResolution;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveFailedQueryRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('resolveFailure', $this->route('query_request')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isReplacement = fn (): bool => $this->input('resolution') === FailureResolution::Replaced->value;

        return [
            'resolution' => ['required', Rule::enum(FailureResolution::class)],
            'note' => [
                'nullable',
                Rule::requiredIf(fn (): bool => ! $isReplacement()),
                'string',
                'max:1000',
            ],
            'replacement_query_request_id' => [
                'nullable',
                Rule::requiredIf($isReplacement),
                Rule::prohibitedIf(fn (): bool => ! $isReplacement()),
                'integer',
                'exists:query_requests,id',
            ],
        ];
    }
}
