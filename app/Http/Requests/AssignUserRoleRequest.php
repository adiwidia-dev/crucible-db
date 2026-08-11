<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssignUserRoleRequest extends FormRequest
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
            'role_assignments' => ['nullable', 'array'],
            'role_assignments.*.role_id' => ['required', 'integer', 'distinct', 'exists:roles,id'],
            'role_assignments.*.selected' => ['sometimes', 'boolean'],
            'role_assignments.*.priority' => ['required', 'integer', 'between:0,9999'],
        ];
    }

    /**
     * @return array<int, int>
     */
    public function roleIds(): array
    {
        return array_keys($this->roleAssignments());
    }

    /**
     * @return array<int, array{priority: int}>
     */
    public function roleAssignments(): array
    {
        /** @var array<int, array{role_id: int|string, selected?: bool|string|int, priority: int|string}> $assignments */
        $assignments = $this->validated('role_assignments', []);
        $selectedAssignments = [];

        foreach ($assignments as $assignment) {
            if (! (bool) ($assignment['selected'] ?? false)) {
                continue;
            }

            $selectedAssignments[(int) $assignment['role_id']] = [
                'priority' => (int) $assignment['priority'],
            ];
        }

        uasort($selectedAssignments, fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);

        return $selectedAssignments;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var array<int, mixed> $assignments */
                $assignments = is_array($this->input('role_assignments'))
                    ? $this->input('role_assignments')
                    : [];

                $priorities = collect($assignments)
                    ->filter(fn (mixed $assignment): bool => is_array($assignment) && (bool) ($assignment['selected'] ?? false))
                    ->pluck('priority')
                    ->filter(fn (mixed $priority): bool => is_numeric($priority))
                    ->map(fn (mixed $priority): int => (int) $priority)
                    ->all();

                if (count($priorities) !== count(array_unique($priorities))) {
                    $validator->errors()->add('role_assignments', 'Selected roles must use unique priorities for this user.');
                }
            },
        ];
    }
}
