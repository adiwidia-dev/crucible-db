<?php

namespace Tests\Feature;

use App\Models\AuthProvider;
use App\Models\Role;
use App\Models\User;
use App\Services\HmacAwareOpenIdConnectProvider;
use App\Services\SsoProviderConfigurator;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Support\SessionKey;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use ReflectionMethod;
use SocialiteProviders\Manager\Config;
use SocialiteProviders\OpenIDConnect\Provider as OpenIdConnectProvider;
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
                ->has('provider_options', 4)
                ->where('provider_options.3.value', 'oidc'));

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

    public function test_admin_can_configure_multiple_oidc_issuers(): void
    {
        $admin = $this->adminUser();

        foreach ([
            ['name' => 'Workforce SSO', 'issuer_url' => 'https://identity.example.com/application/o/crucible'],
            ['name' => 'Partner SSO', 'issuer_url' => 'https://partners.example.com/realms/crucible'],
        ] as $configuration) {
            $this->actingAs($admin)
                ->post(route('auth-providers.store'), [
                    'provider' => 'oidc',
                    'name' => $configuration['name'],
                    'issuer_url' => $configuration['issuer_url'].'/',
                    'client_id' => 'oidc-client-id',
                    'client_secret' => 'oidc-client-secret',
                    'scopes' => 'openid profile email',
                ])
                ->assertRedirect(route('auth-providers.index'));
        }

        $this->assertDatabaseCount('auth_providers', 2);
        $this->assertSame(
            [
                'https://identity.example.com/application/o/crucible',
                'https://partners.example.com/realms/crucible',
            ],
            AuthProvider::query()->orderBy('id')->pluck('issuer_url')->all(),
        );
    }

    public function test_oidc_provider_requires_a_secure_or_loopback_issuer_url(): void
    {
        $admin = $this->adminUser();
        $payload = [
            'provider' => 'oidc',
            'name' => 'OIDC',
            'client_id' => 'oidc-client-id',
            'client_secret' => 'oidc-client-secret',
        ];

        $this->actingAs($admin)
            ->post(route('auth-providers.store'), $payload + [
                'issuer_url' => 'http://identity.example.com/application/o/crucible',
            ])
            ->assertSessionHasErrors('issuer_url');

        $this->actingAs($admin)
            ->post(route('auth-providers.store'), $payload + [
                'issuer_url' => 'http://authentik.localhost:9000/application/o/crucible',
            ])
            ->assertRedirect(route('auth-providers.index'));
    }

    public function test_oidc_driver_receives_strict_database_backed_configuration(): void
    {
        $provider = AuthProvider::factory()->openIdConnect()->create([
            'issuer_url' => 'https://identity.example.com/application/o/crucible',
            'client_id' => 'oidc-client-id',
            'client_secret' => 'oidc-client-secret',
        ]);

        $driver = app(SsoProviderConfigurator::class)->driver($provider);

        $this->assertInstanceOf(OpenIdConnectProvider::class, $driver);
        $this->assertSame(['openid', 'profile', 'email'], $driver->getScopes());

        $getConfig = new ReflectionMethod($driver, 'getConfig');
        $configuration = $getConfig->invoke($driver);

        $this->assertSame($provider->issuer_url, $configuration['base_url']);
        $this->assertSame($provider->callbackUrl(), $configuration['redirect']);
        $this->assertTrue($configuration['require_email']);
        $this->assertTrue($configuration['verify_jwt']);
        $this->assertTrue($configuration['use_nonce']);
        $this->assertSame(10, $configuration['http_timeout']);
        $this->assertSame(5, $configuration['http_connect_timeout']);
    }

    public function test_oidc_driver_verifies_hmac_id_tokens_with_the_client_secret(): void
    {
        $clientSecret = str_repeat('a', 32);

        $driver = new HmacAwareOpenIdConnectProvider(
            Request::create('/auth-providers/1/callback'),
            'oidc-client-id',
            $clientSecret,
            url('/auth-providers/1/callback'),
        );

        $driver->setConfig(new Config(
            'oidc-client-id',
            $clientSecret,
            url('/auth-providers/1/callback'),
            [
                'base_url' => 'https://identity.example.com/application/o/crucible',
                'jwt_algorithm' => 'HS256',
                'verify_jwt' => true,
            ],
        ));

        $token = JWT::encode([
            'sub' => 'oidc-user-123',
            'aud' => 'oidc-client-id',
            'exp' => now()->addMinute()->getTimestamp(),
            'iat' => now()->getTimestamp(),
            'iss' => 'https://identity.example.com/application/o/crucible',
        ], $clientSecret, 'HS256');

        $method = new ReflectionMethod($driver, 'verifyAndDecodeJWT');
        $claims = $method->invoke($driver, $token, 'HS256');

        $this->assertSame('oidc-user-123', $claims->sub);

        $this->expectException(\InvalidArgumentException::class);

        $method->invoke($driver, JWT::encode([
            'sub' => 'oidc-user-123',
            'aud' => 'oidc-client-id',
            'exp' => now()->addMinute()->getTimestamp(),
            'iat' => now()->getTimestamp(),
            'iss' => 'https://identity.example.com/application/o/crucible',
        ], str_repeat('b', 32), 'HS256'), 'HS256');
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
