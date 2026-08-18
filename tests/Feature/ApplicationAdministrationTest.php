<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Models\AuditLog;
use App\Models\AuthProvider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
