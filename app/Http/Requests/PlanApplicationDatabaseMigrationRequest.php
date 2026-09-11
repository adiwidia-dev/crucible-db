<?php

namespace App\Http\Requests;

use App\Enums\ApplicationDatabaseDriver;
use App\Support\ApplicationDatabaseBootstrap;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanApplicationDatabaseMigrationRequest extends FormRequest
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
        $driver = $this->string('driver')->toString();
        $usesSqlite = $driver === ApplicationDatabaseDriver::Sqlite->value;

        return [
            'driver' => ['required', Rule::enum(ApplicationDatabaseDriver::class)],
            'sqlite_database' => [Rule::excludeIf(! $usesSqlite), 'required', 'string', 'max:4096'],
            'host' => [Rule::excludeIf($usesSqlite), 'required', 'string', 'max:255'],
            'port' => [Rule::excludeIf($usesSqlite), 'required', 'integer', 'between:1,65535'],
            'database' => [Rule::excludeIf($usesSqlite), 'required', 'string', 'max:255'],
            'username' => [Rule::excludeIf($usesSqlite), 'required', 'string', 'max:255'],
            'password' => [Rule::excludeIf($usesSqlite), 'required', 'string', 'max:4096'],
            'pgsql_sslmode' => [
                Rule::excludeIf($driver !== ApplicationDatabaseDriver::PostgreSql->value),
                'required',
                Rule::in(['disable', 'prefer', 'require', 'verify-ca', 'verify-full']),
            ],
            'mysql_ssl_ca' => [
                Rule::excludeIf($driver !== ApplicationDatabaseDriver::MySql->value),
                'nullable',
                'string',
                'max:4096',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function destinationPayload(): array
    {
        $values = $this->validated();
        $driver = ApplicationDatabaseDriver::from((string) $values['driver']);

        if ($driver === ApplicationDatabaseDriver::Sqlite) {
            return [
                'version' => ApplicationDatabaseBootstrap::CurrentVersion,
                'driver' => $driver->value,
                'database' => $values['sqlite_database'],
                'foreign_key_constraints' => true,
                'busy_timeout' => 5000,
                'journal_mode' => 'WAL',
                'synchronous' => 'FULL',
                'transaction_mode' => 'IMMEDIATE',
            ];
        }

        $payload = [
            'version' => ApplicationDatabaseBootstrap::CurrentVersion,
            'driver' => $driver->value,
            'host' => $values['host'],
            'port' => $values['port'],
            'database' => $values['database'],
            'username' => $values['username'],
            'password' => $values['password'],
        ];

        if ($driver === ApplicationDatabaseDriver::PostgreSql) {
            $payload['pgsql_sslmode'] = $values['pgsql_sslmode'];
        } elseif (filled($values['mysql_ssl_ca'] ?? null)) {
            $payload['mysql_ssl_ca'] = $values['mysql_ssl_ca'];
        }

        return $payload;
    }
}
