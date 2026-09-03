<?php

namespace App\Http\Requests;

use App\Enums\ApplicationDatabaseDriver;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicationDatabaseConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! User::query()->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $usesSqlite = $this->string('driver')->toString() === ApplicationDatabaseDriver::Sqlite->value;

        return [
            'driver' => ['required', Rule::enum(ApplicationDatabaseDriver::class)],
            'host' => [Rule::excludeIf($usesSqlite), 'required', 'string', 'max:255'],
            'port' => [Rule::excludeIf($usesSqlite), 'required', 'integer', 'between:1,65535'],
            'database' => [Rule::excludeIf($usesSqlite), 'required', 'string', 'max:255'],
            'username' => [Rule::excludeIf($usesSqlite), 'required', 'string', 'max:255'],
            'password' => [Rule::excludeIf($usesSqlite), 'required', 'string', 'max:4096'],
            'pgsql_sslmode' => [
                Rule::excludeIf($this->string('driver')->toString() !== ApplicationDatabaseDriver::PostgreSql->value),
                'required',
                Rule::in(['disable', 'prefer', 'require', 'verify-ca', 'verify-full']),
            ],
            'mysql_ssl_ca' => [
                Rule::excludeIf($this->string('driver')->toString() !== ApplicationDatabaseDriver::MySql->value),
                'nullable',
                'string',
                'max:4096',
            ],
        ];
    }
}
