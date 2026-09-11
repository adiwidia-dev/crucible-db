<?php

namespace App\Http\Requests;

use App\Enums\DatabaseDriver;
use App\Enums\DatabaseTlsMode;
use App\Models\DatabaseConnection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDatabaseConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('database_connection')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('database_connections', 'name')->ignore($this->route('database_connection')),
            ],
            'driver' => ['required', Rule::enum(DatabaseDriver::class)],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:4096'],
            'tls_mode' => ['required', Rule::enum(DatabaseTlsMode::class)],
            'tls_ca_certificate' => ['nullable', 'string', 'max:65535'],
            'tls_client_certificate' => ['nullable', 'string', 'max:65535'],
            'tls_client_key' => ['nullable', 'string', 'max:65535'],
            'tls_material_action' => ['required', Rule::in(['retain', 'replace', 'clear'])],
            'tls_skip_verify' => ['prohibited'],
            'ssl_mode' => ['prohibited'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('tls_material_action')) {
            $this->merge(['tls_material_action' => 'retain']);
        }
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $tlsMode = DatabaseTlsMode::tryFrom($this->string('tls_mode')->toString());
            /** @var DatabaseConnection $databaseConnection */
            $databaseConnection = $this->route('database_connection');
            $tlsMaterialAction = $this->string('tls_material_action')->toString();
            $replacesMaterial = $tlsMaterialAction === 'replace';
            $clearsMaterial = $tlsMaterialAction === 'clear';

            if ($tlsMaterialAction === 'retain' && (
                $this->exists('tls_ca_certificate')
                || $this->exists('tls_client_certificate')
                || $this->exists('tls_client_key')
            )) {
                $validator->errors()->add('tls_material_action', 'Select replace or clear to change TLS material.');
            }

            $caCertificate = $clearsMaterial
                ? null
                : ($replacesMaterial && filled($this->input('tls_ca_certificate'))
                    ? $this->input('tls_ca_certificate')
                    : $databaseConnection->tls_ca_certificate);

            if ($tlsMode?->requiresCaCertificate() && blank($caCertificate)) {
                $validator->errors()->add('tls_ca_certificate', 'A CA certificate is required when TLS verifies the server.');
            }

            $hasClientCertificate = $clearsMaterial
                ? false
                : ($replacesMaterial
                    ? filled($this->input('tls_client_certificate'))
                    : filled($databaseConnection->tls_client_certificate));
            $hasClientKey = $clearsMaterial
                ? false
                : ($replacesMaterial
                    ? filled($this->input('tls_client_key'))
                    : filled($databaseConnection->tls_client_key));

            if ($hasClientCertificate && ! $hasClientKey) {
                $validator->errors()->add('tls_client_key', 'A client key is required with a client certificate.');
            }

            if ($hasClientKey && ! $hasClientCertificate) {
                $validator->errors()->add('tls_client_certificate', 'A client certificate is required with a client key.');
            }
        }];
    }
}
