<?php

namespace Tests\Feature;

use App\Models\AuthProvider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Support\SessionKey;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class AuthProviderSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_provider_management_in_settings(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->get(route('auth-providers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/authentication-providers/index')
                ->has('providers')
                ->has('provider_options'));

        $this->assertSame(
            url('/settings/admin/authentication-providers'),
            route('auth-providers.index'),
        );
    }

    public function test_non_admin_cannot_access_or_test_provider_management(): void
    {
        $developer = $this->developerUser();
        $provider = AuthProvider::factory()->google()->create();

        $this->actingAs($developer)->get(route('auth-providers.index'))->assertForbidden();
        $this->actingAs($developer)->get(route('auth-providers.test', $provider))->assertForbidden();
    }

    public function test_old_provider_management_url_redirects_to_settings(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/auth-providers')
            ->assertRedirect('/settings/admin/authentication-providers');
    }

    public function test_admin_can_create_and_update_provider_configuration(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('auth-providers.store'), [
                'provider' => 'google',
                'name' => 'Google Workspace',
                'client_id' => 'google-client-id',
                'client_secret' => 'google-client-secret',
                'scopes' => 'openid profile email',
                'allowed_domains' => 'Example.com, @Company.test',
                'is_enabled' => '1',
            ])
            ->assertRedirect(route('auth-providers.index'))
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => [
                    'type' => 'success',
                    'message' => 'Auth provider created.',
                ],
            ]);

        $provider = AuthProvider::query()->where('provider', 'google')->firstOrFail();

        $this->assertSame('Google Workspace', $provider->name);
        $this->assertSame('google-client-secret', $provider->client_secret);
        $this->assertTrue($provider->is_enabled);
        $this->assertSame(['openid', 'profile', 'email'], $provider->scopes);
        $this->assertSame(['example.com', 'company.test'], $provider->allowed_domains);
        $this->assertDatabaseMissing('auth_providers', [
            'id' => $provider->id,
            'client_secret' => 'google-client-secret',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth_provider.created',
            'actor_id' => $admin->id,
            'auditable_id' => $provider->id,
        ]);

        $this->actingAs($admin)
            ->patch(route('auth-providers.update', $provider), [
                'provider' => 'google',
                'name' => 'Google SSO',
                'client_id' => 'google-client-id-updated',
                'client_secret' => '',
                'scopes' => '',
                'allowed_domains' => '',
            ])
            ->assertRedirect(route('auth-providers.index'));

        $provider->refresh();

        $this->assertSame('Google SSO', $provider->name);
        $this->assertSame('google-client-id-updated', $provider->client_id);
        $this->assertSame('google-client-secret', $provider->client_secret);
        $this->assertFalse($provider->is_enabled);
        $this->assertNull($provider->scopes);
        $this->assertNull($provider->allowed_domains);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth_provider.updated',
            'actor_id' => $admin->id,
            'auditable_id' => $provider->id,
        ]);
    }

    public function test_admin_can_test_a_disabled_provider_without_linking_an_identity(): void
    {
        $admin = $this->adminUser();
        $provider = AuthProvider::factory()->google()->create(['is_enabled' => false]);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-admin',
            'email' => $admin->email,
            'email_verified' => true,
        ]));

        $this->actingAs($admin)
            ->get(route('auth-providers.test', $provider))
            ->assertRedirect('https://socialite.fake/google/authorize')
            ->assertSessionHas('sso.test', [
                'auth_provider_id' => $provider->id,
                'admin_user_id' => $admin->id,
            ]);

        $this->get(route('auth-providers.callback', $provider))
            ->assertRedirect(route('auth-providers.edit', $provider))
            ->assertSessionMissing('sso.test')
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => [
                    'type' => 'success',
                    'message' => "Google configuration test succeeded for {$admin->email}.",
                ],
            ]);

        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseCount('user_identities', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth_provider.configuration_tested',
            'actor_id' => $admin->id,
            'auditable_id' => $provider->id,
        ]);
    }

    public function test_failed_provider_test_returns_admin_to_provider_settings(): void
    {
        $admin = $this->adminUser();
        $provider = AuthProvider::factory()->google()->create();

        Socialite::fake('google', fn (): never => throw new InvalidStateException);

        $this->actingAs($admin)->get(route('auth-providers.test', $provider));

        $this->get(route('auth-providers.callback', $provider))
            ->assertRedirect(route('auth-providers.edit', $provider))
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => [
                    'type' => 'error',
                    'message' => 'Provider test session expired. Please try again.',
                ],
            ]);

        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth_provider.configuration_tested',
            'actor_id' => $admin->id,
            'auditable_id' => $provider->id,
        ]);
    }

    public function test_authenticated_user_cannot_use_normal_sso_callback(): void
    {
        $provider = AuthProvider::factory()->google()->create();

        $this->actingAs($this->adminUser())
            ->get(route('auth-providers.callback', $provider))
            ->assertForbidden();
    }

    private function adminUser(): User
    {
        $role = Role::factory()->admin()->create();

        return User::factory()->withRole($role)->create();
    }

    private function developerUser(): User
    {
        $role = Role::factory()->developer()->create();

        return User::factory()->withRole($role)->create();
    }
}
