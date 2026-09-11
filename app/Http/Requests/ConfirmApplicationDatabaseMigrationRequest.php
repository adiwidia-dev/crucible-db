<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmApplicationDatabaseMigrationRequest extends FormRequest
{
    public const ActivatePhrase = 'ACTIVATE';

    public const FinalizePhrase = 'FINALIZE';

    public const RollbackPhrase = 'ROLLBACK';

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
            'confirmation' => ['required', 'string', Rule::in([$this->expectedPhrase()])],
        ];
    }

    public function expectedPhrase(): string
    {
        return match ($this->route()?->getName()) {
            'application-database-migrations.activate' => self::ActivatePhrase,
            'application-database-migrations.rollback' => self::RollbackPhrase,
            default => self::FinalizePhrase,
        };
    }
}
