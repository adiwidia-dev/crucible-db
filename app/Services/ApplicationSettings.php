<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class ApplicationSettings
{
    public const AppName = 'app_name';

    public const DefaultTimezone = 'default_timezone';

    public const PasswordLoginEnabled = 'password_login_enabled';

    public const PasskeyLoginEnabled = 'passkey_login_enabled';

    public const MailHost = 'mail_host';

    public const MailPort = 'mail_port';

    public const MailUsername = 'mail_username';

    public const MailPassword = 'mail_password';

    public const MailScheme = 'mail_scheme';

    public const MailFromAddress = 'mail_from_address';

    public const MailFromName = 'mail_from_name';

    /**
     * @var array<string, string|null>|null
     */
    private ?array $cachedValues = null;

    /**
     * @return array<string, string|int|bool|null>
     */
    public function formValues(): array
    {
        $values = $this->values();

        return [
            'app_name' => $this->string(self::AppName, config('app.name'), $values),
            'default_timezone' => $this->defaultTimezone($values),
            'mail_host' => $this->string(self::MailHost, config('mail.mailers.smtp.host'), $values),
            'mail_port' => $this->integer(self::MailPort, (int) config('mail.mailers.smtp.port'), $values),
            'mail_username' => $this->string(self::MailUsername, config('mail.mailers.smtp.username'), $values),
            'mail_scheme' => $this->string(self::MailScheme, config('mail.mailers.smtp.scheme'), $values),
            'mail_from_address' => $this->string(self::MailFromAddress, config('mail.from.address'), $values),
            'mail_from_name' => $this->string(self::MailFromName, config('mail.from.name'), $values),
            'has_mail_password' => array_key_exists(self::MailPassword, $values),
        ];
    }

    public function appName(): string
    {
        return $this->string(self::AppName, (string) config('app.name')) ?? (string) config('app.name');
    }

    /**
     * @param  array<string, string|null>|null  $values
     */
    public function defaultTimezone(?array $values = null): string
    {
        return $this->string(self::DefaultTimezone, (string) config('app.timezone'), $values)
            ?? (string) config('app.timezone');
    }

    public function passwordLoginEnabled(): bool
    {
        return $this->boolean(self::PasswordLoginEnabled, true);
    }

    public function passkeyLoginEnabled(): bool
    {
        return $this->boolean(self::PasskeyLoginEnabled, true);
    }

    /**
     * @param  array<string, string|int|bool|null>  $settings
     */
    public function put(array $settings): void
    {
        foreach ($settings as $key => $value) {
            ApplicationSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => is_bool($value) ? ($value ? '1' : '0') : $value],
            );
        }

        $this->cachedValues = null;

        config(['app.name' => $this->appName()]);
        $this->applyRuntimeConfiguration();
    }

    public function applyRuntimeConfiguration(): void
    {
        if (blank($this->string(self::MailHost))) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $this->string(self::MailHost),
            'mail.mailers.smtp.port' => $this->integer(self::MailPort, 587),
            'mail.mailers.smtp.username' => $this->string(self::MailUsername),
            'mail.mailers.smtp.password' => $this->string(self::MailPassword),
            'mail.mailers.smtp.scheme' => $this->string(self::MailScheme),
            'mail.from.address' => $this->string(self::MailFromAddress, (string) config('mail.from.address')),
            'mail.from.name' => $this->string(self::MailFromName, (string) config('mail.from.name')),
        ]);

        Mail::purge('smtp');
    }

    /**
     * @param  array<string, string|null>|null  $values
     */
    private function string(string $key, ?string $default = null, ?array $values = null): ?string
    {
        return Arr::get($values ?? $this->values(), $key, $default);
    }

    /**
     * @param  array<string, string|null>|null  $values
     */
    private function integer(string $key, int $default, ?array $values = null): int
    {
        return (int) Arr::get($values ?? $this->values(), $key, $default);
    }

    private function boolean(string $key, bool $default): bool
    {
        return filter_var(Arr::get($this->values(), $key, $default), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * @return array<string, string|null>
     */
    private function values(): array
    {
        if ($this->cachedValues !== null) {
            return $this->cachedValues;
        }

        if (! Schema::hasTable('application_settings')) {
            return $this->cachedValues = [];
        }

        /** @var array<string, string|null> $values */
        $values = ApplicationSetting::query()
            ->pluck('value', 'key')
            ->all();

        return $this->cachedValues = $values;
    }
}
