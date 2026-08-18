<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptUserInvitationRequest;
use App\Http\Requests\StoreUserInvitationRequest;
use App\Models\AuthProvider;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use App\Services\ApplicationSettings;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserInvitationController extends Controller
{
    public function create(): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return Inertia::render('users/create');
    }

    public function store(StoreUserInvitationRequest $request, ApplicationSettings $settings, AuditLogger $auditLogger): RedirectResponse
    {
        $token = Str::random(64);
        $name = Str::of($request->validated('first_name').' '.$request->validated('last_name'))
            ->squish()
            ->toString();

        $user = DB::transaction(function () use ($request, $name, $settings, $token): User {
            return User::query()->create([
                'first_name' => $request->validated('first_name'),
                'last_name' => $request->validated('last_name'),
                'name' => $name,
                'email' => $request->validated('email'),
                'timezone' => $settings->defaultTimezone(),
                'password' => Str::random(64),
                'invited_by_id' => $request->user()->id,
                'invited_at' => now(),
                'invitation_token_hash' => hash('sha256', $token),
            ]);
        });

        $user->notify(new UserInvitationNotification($token));

        $auditLogger->log('user.invited', $request->user(), $user, [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'User invitation sent.']);

        return redirect()->route('users.index');
    }

    public function show(User $user, string $token): Response
    {
        $this->ensureInvitationCanBeAccepted($user, $token);

        return Inertia::render('auth/accept-invitation', [
            'accept_url' => request()->fullUrl(),
            'authProviders' => AuthProvider::query()
                ->where('is_enabled', true)
                ->orderBy('provider')
                ->get()
                ->map(fn (AuthProvider $authProvider): array => [
                    'id' => $authProvider->id,
                    'provider' => $authProvider->provider->value,
                    'name' => $authProvider->name,
                    'redirect_url' => URL::temporarySignedRoute(
                        'users.invitations.auth-providers.redirect',
                        now()->addMinutes(15),
                        [
                            'user' => $user->id,
                            'token' => $token,
                            'auth_provider' => $authProvider->id,
                        ],
                    ),
                ]),
            'email' => $user->email,
            'name' => $user->name,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function accept(AcceptUserInvitationRequest $request, User $user, string $token, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureInvitationCanBeAccepted($user, $token);

        $user->forceFill([
            'password' => $request->validated('password'),
            'email_verified_at' => now(),
            'invitation_accepted_at' => now(),
            'invitation_token_hash' => null,
        ])->save();

        $auditLogger->log('user.invitation_accepted', $user, $user, [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Invitation accepted.']);

        return redirect()->route('dashboard');
    }

    private function ensureInvitationCanBeAccepted(User $user, string $token): void
    {
        abort_if($user->invitation_accepted_at !== null, 403);
        abort_if($user->invitation_token_hash === null, 403);
        abort_unless(hash_equals($user->invitation_token_hash, hash('sha256', $token)), 403);
    }
}
