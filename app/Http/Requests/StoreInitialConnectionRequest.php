<?php

namespace App\Http\Requests;

use App\Enums\DatabaseDriver;
use App\Enums\DatabaseTlsMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInitialConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user->isAdmin()
            && $this->session()->get('setup.owner_id') === $user->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:database_connections,name'],
            'driver' => ['required', Rule::enum(DatabaseDriver::class)],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
            'tls_mode' => ['required', Rule::enum(DatabaseTlsMode::class)],
            'tls_ca_certificate' => ['nullable', 'string', 'max:65535'],
            'tls_client_certificate' => ['nullable', 'string', 'max:65535'],
            'tls_client_key' => ['nullable', 'string', 'max:65535'],
            'tls_skip_verify' => ['prohibited'],
            'ssl_mode' => ['prohibited'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $tlsMode = DatabaseTlsMode::tryFrom($this->string('tls_mode')->toString());

            if ($tlsMode?->requiresCaCertificate() && blank($this->input('tls_ca_certificate'))) {
                $validator->errors()->add('tls_ca_certificate', 'A CA certificate is required when TLS verifies the server.');
            }

            $hasClientCertificate = filled($this->input('tls_client_certificate'));
            $hasClientKey = filled($this->input('tls_client_key'));

            if ($hasClientCertificate && ! $hasClientKey) {
                $validator->errors()->add('tls_client_key', 'A client key is required with a client certificate.');
            }

            if ($hasClientKey && ! $hasClientCertificate) {
                $validator->errors()->add('tls_client_certificate', 'A client certificate is required with a client key.');
            }
        }];
    }
}
