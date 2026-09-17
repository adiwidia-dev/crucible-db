<?php

namespace App\Http\Requests;

use App\Enums\SqlPolicyRuleMatchType;
use App\Enums\SqlPolicyRuleScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveSqlPolicyCandidateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['allow', 'deny', 'dismiss'])],
            'match_type' => ['required', Rule::enum(SqlPolicyRuleMatchType::class)],
            'scope_type' => ['required', Rule::enum(SqlPolicyRuleScope::class)],
            'scope_id' => [
                Rule::requiredIf($this->string('scope_type')->toString() !== SqlPolicyRuleScope::Workspace->value),
                'nullable',
                'integer',
                'min:1',
            ],
            'comment' => [
                Rule::requiredIf(in_array($this->string('action')->toString(), ['deny', 'dismiss'], true)),
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }
}
