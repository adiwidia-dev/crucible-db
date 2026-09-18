<?php

namespace App\Http\Requests;

use App\Enums\AccessMode;
use App\Enums\AccessTransport;
use App\Enums\QueryRequestKind;
use App\Enums\QueryType;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreQueryRequestRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('access_transport')) {
            $this->merge(['access_transport' => AccessTransport::Browser->value]);
        }

        if ($this->input('request_kind') === QueryRequestKind::QueryAccess->value
            && ! $this->has('database_connection_ids')
            && $this->filled('database_connection_id')) {
            $this->merge([
                'database_connection_ids' => [$this->input('database_connection_id')],
            ]);
        }

        if ($this->input('request_kind') !== QueryRequestKind::SingleExecution->value) {
            return;
        }

        if (! $this->has('statements') && $this->filled('sql')) {
            $this->merge([
                'statements' => [[
                    'sql' => $this->input('sql'),
                    'database_connection_id' => $this->input('database_connection_id'),
                ]],
            ]);
        }

        $statements = $this->input('statements', []);

        if (! is_array($statements)) {
            return;
        }

        $this->merge([
            'statements' => array_map(
                fn (mixed $statement): mixed => is_array($statement)
                    ? [
                        ...$statement,
                        'database_connection_id' => $statement['database_connection_id'] ?? $this->input('database_connection_id'),
                    ]
                    : $statement,
                $statements,
            ),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', QueryRequest::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'intent' => ['nullable', Rule::in(['submit', 'draft', 'policy_review'])],
            'access_transport' => ['required', Rule::enum(AccessTransport::class)],
            'database_connection_id' => ['nullable', 'integer', 'exists:database_connections,id'],
            'database_connection_ids' => ['nullable', 'required_if:request_kind,'.QueryRequestKind::QueryAccess->value, 'array', 'min:1', 'max:10'],
            'database_connection_ids.*' => ['required', 'integer', 'distinct', 'exists:database_connections,id'],
            'request_kind' => ['required', Rule::enum(QueryRequestKind::class)],
            'requested_access_mode' => [
                'nullable',
                'required_if:request_kind,'.QueryRequestKind::QueryAccess->value,
                Rule::in([AccessMode::Read->value, AccessMode::Write->value]),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'statements' => ['nullable', 'required_if:request_kind,'.QueryRequestKind::SingleExecution->value, 'array', 'min:1', 'max:50'],
            'statements.*.sql' => ['required', 'string', 'max:1000000'],
            'statements.*.database_connection_id' => ['required_if:request_kind,'.QueryRequestKind::SingleExecution->value, 'integer', 'exists:database_connections,id'],
            'sql' => ['nullable', 'string', 'max:1000000'],
            'schedule_query' => ['nullable', 'boolean'],
            'scheduled_at' => ['nullable', 'required_if:schedule_query,1', 'date', 'after:now'],
            'access_duration_minutes' => ['nullable', 'required_if:request_kind,'.QueryRequestKind::QueryAccess->value, 'integer', 'min:5', 'max:1440'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->validateNativeClientAccess($validator);
        }];
    }

    private function validateNativeClientAccess(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()
            || $this->string('access_transport')->toString() !== AccessTransport::NativeProxy->value) {
            return;
        }

        if ($this->string('request_kind')->toString() !== QueryRequestKind::QueryAccess->value) {
            $validator->errors()->add('access_transport', 'Native Client Access is available only for Query Access requests.');

            return;
        }

        if ($this->string('intent')->toString() === 'draft') {
            $validator->errors()->add('intent', 'Only deployment batches can be saved as drafts.');
        }

        $connectionIds = $this->validatedConnectionIds();

        if (count($connectionIds) !== 1) {
            $validator->errors()->add('database_connection_ids', 'Native Client Access requires exactly one active database connection.');

            return;
        }

        $connection = DatabaseConnection::query()->find($connectionIds[0]);

        if (! $connection?->is_active) {
            $validator->errors()->add('database_connection_ids', 'Native Client Access requires an active database connection.');

            return;
        }

        $requestedAccessMode = AccessMode::tryFrom($this->string('requested_access_mode')->toString());
        $queryType = $requestedAccessMode === AccessMode::Write ? QueryType::Write : QueryType::Read;
        $permission = $this->user()?->effectiveNativeProxyPermissionFor($connection, $queryType);

        if (! $permission || ! $permission['native_proxy_access_mode']->allows($queryType)) {
            $validator->errors()->add('requested_access_mode', 'Your role is not allowed to request the selected Native Client Access level for this database.');
        }
    }

    /**
     * @return array<int, int>
     */
    private function validatedConnectionIds(): array
    {
        $connectionIds = $this->input('database_connection_ids', []);

        return collect(is_array($connectionIds) ? $connectionIds : [])
            ->filter(fn (mixed $connectionId): bool => is_numeric($connectionId))
            ->map(fn (mixed $connectionId): int => (int) $connectionId)
            ->values()
            ->all();
    }
}
