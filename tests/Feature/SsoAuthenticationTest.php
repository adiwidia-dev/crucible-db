<?php

namespace Tests\Feature;

use App\Enums\AuthProviderType;
use App\Models\AuthProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class SsoAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create();
    }

    public function test_enabled_provider_redirects_to_socialite(): void
    {
        $provider = AuthProvider::factory()->google()->create();

        Socialite::fake('google');

        $response = $this->get(route('auth-providers.redirect', $provider));

        $response->assertRedirect('https://socialite.fake/google/authorize');
    }

    public function test_disabled_provider_cannot_be_used(): void
    {
        $provider = AuthProvider::factory()->google()->create(['is_enabled' => false]);

        $this->get(route('auth-providers.redirect', $provider))->assertNotFound();
        $this->get(route('auth-providers.callback', $provider))->assertNotFound();
    }

    public function test_linked_identity_can_authenticate(): void
    {
        $provider = AuthProvider::factory()->google()->create();
        $user = User::factory()->create(['email' => 'person@example.com']);
        $user->identities()->create([
            'auth_provider_id' => $provider->id,
            'provider' => AuthProviderType::Google->value,
            'provider_user_id' => 'google-123',
            'email' => $user->email,
            'name' => $user->name,
        ]);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-123',
            'email' => 'PERSON@example.com',
            'name' => 'Updated Name',
            'email_verified' => true,
        ]));

        $response = $this->get(route('auth-providers.callback', $provider));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Updated Name', $user->identities()->firstOrFail()->name);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth_provider.login',
            'actor_id' => $user->id,
        ]);
    }

    public function test_unlinked_identity_requires_an_invitation(): void
    {
        $provider = AuthProvider::factory()->google()->create();

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-123',
            'email' => 'person@example.com',
            'email_verified' => true,
        ]));

        $response = $this->get(route('auth-providers.callback', $provider));

        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Ask an admin for an invitation before using SSO.']);
        $this->assertGuest();
    }

    public function test_invited_user_can_accept_with_sso(): void
    {
        $provider = AuthProvider::factory()->google()->create();
        $token = 'valid-invitation-token';
        $user = User::factory()->unverified()->create([
            'email' => 'person@example.com',
            'invited_at' => now(),
            'invitation_token_hash' => hash('sha256', $token),
        ]);
        $redirectUrl = URL::temporarySignedRoute(
            'users.invitations.auth-providers.redirect',
            now()->addMinutes(15),
            ['user' => $user->id, 'token' => $token, 'auth_provider' => $provider->id],
        );

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-123',
            'email' => $user->email,
            'name' => $user->name,
            'email_verified' => true,
        ]));

        $this->get($redirectUrl)->assertRedirect('https://socialite.fake/google/authorize');
        $response = $this->get(route('auth-providers.callback', $provider));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $user->refresh();
        $this->assertNotNull($user->invitation_accepted_at);
        $this->assertNull($user->invitation_token_hash);
        $this->assertDatabaseHas('user_identities', [
            'user_id' => $user->id,
            'provider' => AuthProviderType::Google->value,
            'provider_user_id' => 'google-123',
        ]);
    }

    public function test_provider_domain_restrictions_are_enforced(): void
    {
        $provider = AuthProvider::factory()->google()->create([
            'allowed_domains' => ['example.com'],
        ]);
        User::factory()->unverified()->create([
            'email' => 'person@other.example',
            'invited_at' => now(),
            'invitation_token_hash' => hash('sha256', 'token'),
        ]);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-123',
            'email' => 'person@other.example',
            'email_verified' => true,
        ]));

        $this->get(route('auth-providers.callback', $provider))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'This email domain is not allowed for that SSO provider.']);
    }

    public function test_disabled_linked_user_cannot_authenticate(): void
    {
        $provider = AuthProvider::factory()->google()->create();
        $user = User::factory()->disabled()->create();
        $user->identities()->create([
            'auth_provider_id' => $provider->id,
            'provider' => AuthProviderType::Google->value,
            'provider_user_id' => 'google-123',
            'email' => $user->email,
        ]);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-123',
            'email' => $user->email,
            'email_verified' => true,
        ]));

        $this->get(route('auth-providers.callback', $provider))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'This account is disabled.']);
        $this->assertGuest();
    }

    public function test_provider_errors_are_returned_as_login_errors(): void
    {
        $provider = AuthProvider::factory()->google()->create();

        Socialite::fake('google', fn (): never => throw new InvalidStateException);

        $this->get(route('auth-providers.callback', $provider))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'SSO session expired. Please try again.']);
    }

    public function test_google_login_requires_a_verified_email_claim(): void
    {
        $provider = AuthProvider::factory()->google()->create();
        $user = User::factory()->create(['email' => 'person@example.com']);
        $user->identities()->create([
            'auth_provider_id' => $provider->id,
            'provider' => AuthProviderType::Google->value,
            'provider_user_id' => 'google-123',
            'email' => $user->email,
        ]);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-123',
            'email' => $user->email,
            'email_verified' => false,
        ]));

        $this->get(route('auth-providers.callback', $provider))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'The provider could not verify this email address.']);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth_provider.login_rejected',
            'auditable_id' => $provider->id,
        ]);
    }

    public function test_github_email_from_verified_email_scope_is_trusted(): void
    {
        $provider = AuthProvider::factory()->github()->create();
        $user = User::factory()->create(['email' => 'person@example.com']);
        $user->identities()->create([
            'auth_provider_id' => $provider->id,
            'provider' => AuthProviderType::GitHub->value,
            'provider_user_id' => 'github-123',
            'email' => $user->email,
        ]);

        Socialite::fake('github', SocialiteUser::fake([
            'id' => 'github-123',
            'email' => $user->email,
        ]));

        $this->get(route('auth-providers.callback', $provider))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_microsoft_directory_principal_email_is_trusted(): void
    {
        $provider = AuthProvider::factory()->microsoft()->create();
        $user = User::factory()->create(['email' => 'person@example.com']);
        $user->identities()->create([
            'auth_provider_id' => $provider->id,
            'provider' => AuthProviderType::Microsoft->value,
            'provider_user_id' => 'microsoft-123',
            'email' => $user->email,
        ]);

        Socialite::fake('microsoft', SocialiteUser::fake([
            'id' => 'microsoft-123',
            'email' => $user->email,
            'userPrincipalName' => $user->email,
        ]));

        $this->get(route('auth-providers.callback', $provider))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }
}
