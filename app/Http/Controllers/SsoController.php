<?php

namespace App\Http\Controllers;

use App\Models\AuthProvider;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\AuditLogger;
use App\Services\SsoIdentityVerifier;
use App\Services\SsoProviderConfigurator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class SsoController extends Controller
{
    public function redirect(AuthProvider $authProvider, SsoProviderConfigurator $configurator): SymfonyRedirectResponse
    {
        abort_unless($authProvider->is_enabled, 404);

        session()->forget('sso.invitation');

        return $configurator
            ->driver($authProvider)
            ->scopes($authProvider->effectiveScopes())
            ->redirect();
    }

    public function invitationRedirect(User $user, string $token, AuthProvider $authProvider, SsoProviderConfigurator $configurator): SymfonyRedirectResponse
    {
        abort_unless($authProvider->is_enabled, 404);
        $this->ensureInvitationCanBeAccepted($user, $token);

        session([
            'sso.invitation' => [
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $token),
                'auth_provider_id' => $authProvider->id,
            ],
        ]);

        return $configurator
            ->driver($authProvider)
            ->scopes($authProvider->effectiveScopes())
            ->redirect();
    }

    public function testRedirect(Request $request, AuthProvider $authProvider, SsoProviderConfigurator $configurator): SymfonyRedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $request->session()->forget('sso.invitation');
        $request->session()->put('sso.test', [
            'auth_provider_id' => $authProvider->id,
            'admin_user_id' => $request->user()->id,
        ]);

        return $configurator
            ->driver($authProvider)
            ->scopes($authProvider->effectiveScopes())
            ->redirect();
    }

    public function callback(
        Request $request,
        AuthProvider $authProvider,
        SsoProviderConfigurator $configurator,
        SsoIdentityVerifier $identityVerifier,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $testContext = $request->session()->pull('sso.test');

        if (is_array($testContext)) {
            return $this->completeConfigurationTest(
                $request,
                $authProvider,
                $configurator,
                $identityVerifier,
                $auditLogger,
                $testContext,
            );
        }

        abort_if($request->user() !== null, 403);
        abort_unless($authProvider->is_enabled, 404);

        try {
            $providerUser = $configurator->driver($authProvider)->user();
        } catch (InvalidStateException) {
            return $this->rejectLogin('SSO session expired. Please try again.');
        } catch (Throwable $exception) {
            report($exception);

            return $this->rejectLogin('SSO authentication failed. Please try again.');
        }

        $email = Str::lower((string) $providerUser->getEmail());

        if ($email === '') {
            return $this->rejectLogin('The provider did not return an email address.');
        }

        if ((string) $providerUser->getId() === '') {
            return $this->rejectLogin('The provider did not return a stable user identifier.');
        }

        if (! $identityVerifier->hasTrustedEmail($authProvider, $providerUser)) {
            $auditLogger->log('auth_provider.login_rejected', null, $authProvider, [
                'provider' => $authProvider->provider->value,
                'email' => $email,
                'reason' => 'email_not_verified',
            ], $request);

            return $this->rejectLogin('The provider could not verify this email address.');
        }

        if (! $this->domainIsAllowed($authProvider, $email)) {
            $auditLogger->log('auth_provider.login_rejected', null, $authProvider, [
                'provider' => $authProvider->provider->value,
                'email' => $email,
                'reason' => 'domain_not_allowed',
            ], $request);

            return $this->rejectLogin('This email domain is not allowed for that SSO provider.');
        }

        $identity = UserIdentity::query()
            ->with('user')
            ->where('provider', $authProvider->provider->value)
            ->where('provider_user_id', (string) $providerUser->getId())
            ->first();

        if ($identity instanceof UserIdentity) {
            return $this->loginLinkedIdentity($request, $identity, $authProvider, $providerUser, $auditLogger);
        }

        $invitedUser = $this->invitedUserFromSession($authProvider)
            ?? $this->pendingInvitedUserByEmail($email);

        if (! $invitedUser instanceof User) {
            $auditLogger->log('auth_provider.login_rejected', null, $authProvider, [
                'provider' => $authProvider->provider->value,
                'email' => $email,
                'reason' => 'invitation_required',
            ], $request);

            return $this->rejectLogin('Ask an admin for an invitation before using SSO.');
        }

        if (! hash_equals(Str::lower($invitedUser->email), $email)) {
            $auditLogger->log('auth_provider.login_rejected', null, $authProvider, [
                'provider' => $authProvider->provider->value,
                'email' => $email,
                'invited_user_id' => $invitedUser->id,
                'reason' => 'email_mismatch',
            ], $request);

            return $this->rejectLogin('The SSO email does not match the invited user.');
        }

        if ($invitedUser->isDisabled()) {
            return $this->rejectDisabledUser($request, $auditLogger, $authProvider, $invitedUser);
        }

        return $this->acceptInvitationWithProvider($request, $authProvider, $providerUser, $invitedUser, $auditLogger);
    }

    /**
     * @param  array<string, mixed>  $testContext
     */
    private function completeConfigurationTest(
        Request $request,
        AuthProvider $authProvider,
        SsoProviderConfigurator $configurator,
        SsoIdentityVerifier $identityVerifier,
        AuditLogger $auditLogger,
        array $testContext,
    ): RedirectResponse {
        $admin = $request->user();

        abort_unless(
            $admin?->isAdmin()
                && ($testContext['admin_user_id'] ?? null) === $admin->id
                && ($testContext['auth_provider_id'] ?? null) === $authProvider->id,
            403,
        );

        try {
            $providerUser = $configurator->driver($authProvider)->user();
        } catch (InvalidStateException) {
            return $this->rejectConfigurationTest($request, $authProvider, $auditLogger, 'invalid_state', 'Provider test session expired. Please try again.');
        } catch (Throwable $exception) {
            report($exception);

            return $this->rejectConfigurationTest($request, $authProvider, $auditLogger, 'provider_error', 'Provider configuration test failed. Check the credentials and callback URL.');
        }

        $email = Str::lower(trim((string) $providerUser->getEmail()));

        if ((string) $providerUser->getId() === '') {
            return $this->rejectConfigurationTest($request, $authProvider, $auditLogger, 'missing_provider_id', 'The provider did not return a stable user identifier.');
        }

        if ($email === '') {
            return $this->rejectConfigurationTest($request, $authProvider, $auditLogger, 'missing_email', 'The provider did not return an email address.');
        }

        if (! $identityVerifier->hasTrustedEmail($authProvider, $providerUser)) {
            return $this->rejectConfigurationTest($request, $authProvider, $auditLogger, 'email_not_verified', 'The provider could not verify the returned email address.');
        }

        $auditLogger->log('auth_provider.configuration_tested', $admin, $authProvider, [
            'auth_provider_id' => $authProvider->id,
            'provider' => $authProvider->provider->value,
            'email' => $email,
            'result' => 'success',
        ], $request);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$authProvider->name} configuration test succeeded for {$email}.",
        ]);

        return redirect()->route('auth-providers.edit', $authProvider);
    }

    private function rejectConfigurationTest(
        Request $request,
        AuthProvider $authProvider,
        AuditLogger $auditLogger,
        string $reason,
        string $message,
    ): RedirectResponse {
        $auditLogger->log('auth_provider.configuration_tested', $request->user(), $authProvider, [
            'auth_provider_id' => $authProvider->id,
            'provider' => $authProvider->provider->value,
            'result' => 'failed',
            'reason' => $reason,
        ], $request);

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return redirect()->route('auth-providers.edit', $authProvider);
    }

    private function loginLinkedIdentity(Request $request, UserIdentity $identity, AuthProvider $authProvider, SocialiteUser $providerUser, AuditLogger $auditLogger): RedirectResponse
    {
        $user = $identity->user;

        if (! $user instanceof User || $user->isDisabled()) {
            return $this->rejectDisabledUser($request, $auditLogger, $authProvider, $user);
        }

        $identity->update([
            'auth_provider_id' => $authProvider->id,
            'email' => Str::lower((string) $providerUser->getEmail()),
            'name' => $providerUser->getName(),
            'avatar' => $providerUser->getAvatar(),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        $auditLogger->log('auth_provider.login', $user, $identity, [
            'auth_provider_id' => $authProvider->id,
            'provider' => $authProvider->provider->value,
        ], $request);

        return redirect()->route('dashboard');
    }

    private function acceptInvitationWithProvider(Request $request, AuthProvider $authProvider, SocialiteUser $providerUser, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        $identity = DB::transaction(function () use ($authProvider, $providerUser, $user): UserIdentity {
            $user->forceFill([
                'email_verified_at' => now(),
                'invitation_accepted_at' => now(),
                'invitation_token_hash' => null,
            ])->save();

            return $user->identities()->updateOrCreate(
                ['provider' => $authProvider->provider->value],
                [
                    'auth_provider_id' => $authProvider->id,
                    'provider_user_id' => (string) $providerUser->getId(),
                    'email' => Str::lower((string) $providerUser->getEmail()),
                    'name' => $providerUser->getName(),
                    'avatar' => $providerUser->getAvatar(),
                ],
            );
        });

        $request->session()->forget('sso.invitation');
        Auth::login($user);
        $request->session()->regenerate();

        $auditLogger->log('user.invitation_accepted_sso', $user, $user, [
            'user_id' => $user->id,
            'email' => $user->email,
            'auth_provider_id' => $authProvider->id,
            'provider' => $authProvider->provider->value,
            'user_identity_id' => $identity->id,
        ], $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Invitation accepted with SSO.']);

        return redirect()->route('dashboard');
    }

    private function rejectLogin(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    private function rejectDisabledUser(Request $request, AuditLogger $auditLogger, AuthProvider $authProvider, ?User $user): RedirectResponse
    {
        $auditLogger->log('auth_provider.login_rejected', $user, $authProvider, [
            'auth_provider_id' => $authProvider->id,
            'provider' => $authProvider->provider->value,
            'reason' => 'user_disabled',
        ], $request);

        return $this->rejectLogin('This account is disabled.');
    }

    private function domainIsAllowed(AuthProvider $authProvider, string $email): bool
    {
        $allowedDomains = $authProvider->allowed_domains ?: [];

        if ($allowedDomains === []) {
            return true;
        }

        $domain = Str::lower(Str::after($email, '@'));

        return in_array($domain, $allowedDomains, true);
    }

    private function invitedUserFromSession(AuthProvider $authProvider): ?User
    {
        $context = session('sso.invitation');

        if (! is_array($context) || ($context['auth_provider_id'] ?? null) !== $authProvider->id) {
            return null;
        }

        $user = User::query()->find($context['user_id'] ?? null);

        if (! $user instanceof User) {
            return null;
        }

        if (($context['token_hash'] ?? null) !== $user->invitation_token_hash) {
            return null;
        }

        return $user->invitation_accepted_at === null ? $user : null;
    }

    private function pendingInvitedUserByEmail(string $email): ?User
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNotNull('invited_at')
            ->whereNull('invitation_accepted_at')
            ->whereNotNull('invitation_token_hash')
            ->first();
    }

    private function ensureInvitationCanBeAccepted(User $user, string $token): void
    {
        abort_if($user->invitation_accepted_at !== null, 403);
        abort_if($user->invitation_token_hash === null, 403);
        abort_unless(hash_equals($user->invitation_token_hash, hash('sha256', $token)), 403);
    }
}
