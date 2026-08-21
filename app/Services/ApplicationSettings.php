<?php

namespace App\Services;

use App\Enums\SqlStatementFamily;
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

    public const NotificationsInAppEnabled = 'notifications_in_app_enabled';

    public const NotificationsEmailEnabled = 'notifications_email_enabled';

    public const NotificationsReviewEnabled = 'notifications_review_enabled';

    public const NotificationsExecutionCompletedEnabled = 'notifications_execution_completed_enabled';

    public const NotificationsExecutionFailedEnabled = 'notifications_execution_failed_enabled';

    public const NotificationsQueryAccessEnabled = 'notifications_query_access_enabled';

    public const NotificationsConnectionFailedEnabled = 'notifications_connection_failed_enabled';

    public const SqlReadQueriesEnabled = 'sql_read_queries_enabled';

    public const SqlAllStatementFamiliesEnabled = 'sql_all_statement_families_enabled';

    public const SqlEmergencyFallbackEnabled = 'sql_emergency_fallback_enabled';

    public const SqlInsertEnabled = 'sql_insert_enabled';

    public const SqlUpdateEnabled = 'sql_update_enabled';

    public const SqlDeleteEnabled = 'sql_delete_enabled';

    public const SqlCreateTableEnabled = 'sql_create_table_enabled';

    public const SqlAlterTableEnabled = 'sql_alter_table_enabled';

    public const SqlDropTableEnabled = 'sql_drop_table_enabled';

    public const SqlTruncateTableEnabled = 'sql_truncate_table_enabled';

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
            ...$this->sqlStatementPolicyValues($values),
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function sqlStatementPolicyFormValues(): array
    {
        return $this->sqlStatementPolicyValues($this->values());
    }

    public function allowsSqlStatementFamily(SqlStatementFamily $statementFamily): bool
    {
        if ($this->allowsAllSqlStatementFamilies()) {
            return true;
        }

        return $this->boolean(
            $statementFamily->settingKey(),
            $statementFamily->isEnabledByDefault(),
        );
    }

    public function allowsAllSqlStatementFamilies(): bool
    {
        return $this->boolean(self::SqlAllStatementFamiliesEnabled, false);
    }

    public function allowsEmergencySqlFallback(): bool
    {
        return $this->boolean(self::SqlEmergencyFallbackEnabled, false);
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

    /**
     * @return array<string, bool>
     */
    public function notificationFormValues(): array
    {
        return [
            'notifications_in_app_enabled' => $this->notificationsInAppEnabled(),
            'notifications_email_enabled' => $this->notificationsEmailEnabled(),
            'notifications_review_enabled' => $this->notificationEventEnabled('review'),
            'notifications_execution_completed_enabled' => $this->notificationEventEnabled('execution_completed'),
            'notifications_execution_failed_enabled' => $this->notificationEventEnabled('execution_failed'),
            'notifications_query_access_enabled' => $this->notificationEventEnabled('query_access'),
            'notifications_connection_failed_enabled' => $this->notificationEventEnabled('connection_failed'),
        ];
    }

    public function notificationsInAppEnabled(): bool
    {
        return $this->boolean(self::NotificationsInAppEnabled, true);
    }

    public function notificationsEmailEnabled(): bool
    {
        return $this->boolean(self::NotificationsEmailEnabled, true);
    }

    public function notificationEventEnabled(string $event): bool
    {
        return match ($event) {
            'review' => $this->boolean(self::NotificationsReviewEnabled, true),
            'execution_completed' => $this->boolean(self::NotificationsExecutionCompletedEnabled, true),
            'execution_failed' => $this->boolean(self::NotificationsExecutionFailedEnabled, true),
            'query_access' => $this->boolean(self::NotificationsQueryAccessEnabled, true),
            'connection_failed' => $this->boolean(self::NotificationsConnectionFailedEnabled, true),
            default => false,
        };
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
     * @param  array<string, string|null>  $values
     * @return array<string, bool>
     */
    private function sqlStatementPolicyValues(array $values): array
    {
        $settings = [
            self::SqlAllStatementFamiliesEnabled => filter_var(
                Arr::get($values, self::SqlAllStatementFamiliesEnabled, false),
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            ) ?? false,
            self::SqlEmergencyFallbackEnabled => filter_var(
                Arr::get($values, self::SqlEmergencyFallbackEnabled, false),
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            ) ?? false,
        ];

        foreach (SqlStatementFamily::cases() as $statementFamily) {
            $settings[$statementFamily->settingKey()] = filter_var(
                Arr::get(
                    $values,
                    $statementFamily->settingKey(),
                    $statementFamily->isEnabledByDefault(),
                ),
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            ) ?? $statementFamily->isEnabledByDefault();
        }

        return $settings;
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
