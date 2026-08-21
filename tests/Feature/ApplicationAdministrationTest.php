<?php

namespace Tests\Feature;

use App\Enums\QueryType;
use App\Enums\SqlStatementFamily;
use App\Models\ApplicationSetting;
use App\Models\AuditLog;
use App\Models\AuthProvider;
use App\Models\Role;
use App\Models\User;
use App\Services\ApplicationSettings;
use App\Services\QueryGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApplicationAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_settings_can_be_viewed_without_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('security.edit'))
            ->assertOk();
    }

    public function test_only_administrators_can_open_admin_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('application-settings.edit'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('sql-statement-policy.edit'))
            ->assertForbidden();
    }

    public function test_administrator_can_view_the_default_timezone_setting(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->get(route('application-settings.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('settings.default_timezone', 'UTC')
                ->has('timezones'));
    }

    public function test_administrator_can_update_application_settings_without_exposing_smtp_secret(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->patch(route('application-settings.update'), [
                'app_name' => 'Operations Crucible',
                'default_timezone' => 'Asia/Jakarta',
                'mail_host' => 'smtp.example.com',
                'mail_port' => 587,
                'mail_username' => 'mailer',
                'mail_password' => 'smtp-secret',
                'mail_scheme' => 'smtp',
                'mail_from_address' => 'access@example.test',
                'mail_from_name' => 'Operations Crucible',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'smtp-secret',
            ApplicationSetting::query()->where('key', 'mail_password')->firstOrFail()->value,
        );
        $this->assertSame(
            'Asia/Jakarta',
            ApplicationSetting::query()->where('key', 'default_timezone')->firstOrFail()->value,
        );
        $auditLog = AuditLog::query()->latest('id')->firstOrFail();

        $this->assertSame('application_settings.updated', $auditLog->action);
        $this->assertArrayNotHasKey('mail_password', $auditLog->metadata['before']);
        $this->assertArrayNotHasKey('mail_password', $auditLog->metadata['after']);
    }

    public function test_administrator_must_choose_a_valid_default_timezone(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->from(route('application-settings.edit'))
            ->patch(route('application-settings.update'), [
                'app_name' => 'Operations Crucible',
                'default_timezone' => 'Not/A-Timezone',
            ])
            ->assertRedirect(route('application-settings.edit'))
            ->assertSessionHasErrors('default_timezone');
    }

    public function test_administrator_can_enable_alter_table_statements_for_governed_requests(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->get(route('sql-statement-policy.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/admin/sql-policy')
                ->where('settings.sql_alter_table_enabled', false));

        $this->actingAs($admin)
            ->patch(route('sql-statement-policy.update'), [
                ...$this->sqlStatementPolicyPayload([
                    'sql_alter_table_enabled' => true,
                ]),
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(app(ApplicationSettings::class)->allowsSqlStatementFamily(SqlStatementFamily::AlterTable));
        $this->assertSame(QueryType::Write, app(QueryGuard::class)->classify('ALTER TABLE employee ADD COLUMN region VARCHAR(100)'));
    }

    public function test_administrator_can_allow_all_governed_statement_families(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->patch(route('sql-statement-policy.update'), [
                ...$this->sqlStatementPolicyPayload([
                    'sql_all_statement_families_enabled' => true,
                ]),
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(app(ApplicationSettings::class)->allowsAllSqlStatementFamilies());
        $this->assertTrue(app(ApplicationSettings::class)->allowsSqlStatementFamily(SqlStatementFamily::DropTable));
        $this->assertSame(QueryType::Write, app(QueryGuard::class)->classify('DROP TABLE employee'));
    }

    public function test_administrator_can_enable_the_emergency_sql_fallback(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->patch(route('sql-statement-policy.update'), [
                ...$this->sqlStatementPolicyPayload([
                    'sql_emergency_fallback_enabled' => true,
                ]),
            ])
            ->assertSessionHasNoErrors();

        $guard = app(QueryGuard::class);
        $this->assertTrue(app(ApplicationSettings::class)->allowsEmergencySqlFallback());
        $this->assertSame(QueryType::Write, $guard->classify('CREATE INDEX users_email_index ON users (email)'));
        $this->assertTrue($guard->usesEmergencySqlFallback('CREATE INDEX users_email_index ON users (email)'));

        $auditLog = AuditLog::query()
            ->where('action', 'sql_statement_policy.emergency_fallback_toggled')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($admin->id, $auditLog->actor_id);
        $this->assertTrue($auditLog->metadata['enabled']);
    }

    public function test_administrative_sql_remains_blocked_when_schema_statement_families_are_enabled(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->patch(route('sql-statement-policy.update'), [
                ...$this->sqlStatementPolicyPayload([
                    'sql_alter_table_enabled' => true,
                    'sql_drop_table_enabled' => true,
                    'sql_truncate_table_enabled' => true,
                ]),
            ])
            ->assertSessionHasNoErrors();

        try {
            app(QueryGuard::class)->classify('ALTER USER reporting_user WITH SUPERUSER');
            $this->fail('Administrative SQL should remain blocked.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Administrative, file, security-management, and procedural SQL statements are blocked.'],
                $exception->errors()['sql'],
            );
        }
    }

    public function test_administrator_cannot_disable_every_login_method_without_an_enabled_sso_provider(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->from(route('authentication-methods.edit'))
            ->patch(route('authentication-methods.update'), [
                'password_login_enabled' => false,
                'passkey_login_enabled' => false,
            ])
            ->assertRedirect(route('authentication-methods.edit'))
            ->assertSessionHasErrors('password_login_enabled');
    }

    public function test_administrator_can_use_enabled_sso_as_the_only_login_method(): void
    {
        $admin = $this->administrator();
        AuthProvider::factory()->google()->create(['is_enabled' => true]);

        $this->actingAs($admin)
            ->patch(route('authentication-methods.update'), [
                'password_login_enabled' => false,
                'passkey_login_enabled' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('0', ApplicationSetting::query()->where('key', 'password_login_enabled')->firstOrFail()->value);
        $this->assertSame('0', ApplicationSetting::query()->where('key', 'passkey_login_enabled')->firstOrFail()->value);
    }

    public function test_administrator_can_view_configured_sso_provider_statuses_in_sign_in_methods(): void
    {
        $admin = $this->administrator();
        $enabledProvider = AuthProvider::factory()->google()->create([
            'name' => 'Google Workspace',
            'is_enabled' => true,
        ]);
        $disabledProvider = AuthProvider::factory()->github()->create([
            'name' => 'GitHub Enterprise',
            'is_enabled' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('authentication-methods.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('enabled_provider_count', 1)
                ->has('configured_sso_providers', 2)
                ->where('configured_sso_providers.0', [
                    'id' => $disabledProvider->id,
                    'name' => 'GitHub Enterprise',
                    'provider_label' => 'GitHub',
                    'is_enabled' => false,
                ])
                ->where('configured_sso_providers.1', [
                    'id' => $enabledProvider->id,
                    'name' => 'Google Workspace',
                    'provider_label' => 'Google',
                    'is_enabled' => true,
                ]));
    }

    private function administrator(): User
    {
        $role = Role::factory()->admin()->create();

        return User::factory()->withRole($role)->create();
    }

    /**
     * @param  array<string, bool>  $overrides
     * @return array<string, bool>
     */
    private function sqlStatementPolicyPayload(array $overrides = []): array
    {
        return [
            'sql_all_statement_families_enabled' => false,
            'sql_emergency_fallback_enabled' => false,
            'sql_read_queries_enabled' => true,
            'sql_insert_enabled' => true,
            'sql_update_enabled' => true,
            'sql_delete_enabled' => true,
            'sql_create_table_enabled' => true,
            'sql_alter_table_enabled' => false,
            'sql_drop_table_enabled' => false,
            'sql_truncate_table_enabled' => false,
            ...$overrides,
        ];
    }
}
