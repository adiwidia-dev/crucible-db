<?php

namespace App\Http\Controllers;

use App\Enums\AuthProviderType;
use App\Http\Requests\StoreAuthProviderRequest;
use App\Http\Requests\UpdateAuthProviderRequest;
use App\Models\AuthProvider;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AuthProviderController extends Controller
{
    public function index(): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return Inertia::render('settings/authentication-providers/index', [
            'providers' => AuthProvider::query()
                ->withCount('userIdentities')
                ->orderBy('provider')
                ->get()
                ->map(fn (AuthProvider $authProvider): array => $this->providerPayload($authProvider)),
            'provider_options' => $this->providerOptions(),
        ]);
    }

    public function create(): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return Inertia::render('settings/authentication-providers/form', [
            'provider' => null,
            'provider_options' => $this->providerOptions(),
        ]);
    }

    public function store(StoreAuthProviderRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $authProvider = AuthProvider::query()->create($request->providerAttributes());

        $auditLogger->log('auth_provider.created', $request->user(), $authProvider, [
            'auth_provider_id' => $authProvider->id,
            'provider' => $authProvider->provider->value,
            'is_enabled' => $authProvider->is_enabled,
            'allowed_domains' => $authProvider->allowed_domains,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Auth provider created.']);

        return redirect()->route('auth-providers.index');
    }

    public function show(AuthProvider $authProvider): RedirectResponse
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return redirect()->route('auth-providers.edit', $authProvider);
    }

    public function edit(AuthProvider $authProvider): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return Inertia::render('settings/authentication-providers/form', [
            'provider' => $this->providerPayload($authProvider),
            'provider_options' => $this->providerOptions(),
        ]);
    }

    public function update(UpdateAuthProviderRequest $request, AuthProvider $authProvider, AuditLogger $auditLogger): RedirectResponse
    {
        $before = $authProvider->only(['provider', 'name', 'client_id', 'scopes', 'allowed_domains', 'tenant', 'is_enabled']);

        $authProvider->update($request->providerAttributes());

        $auditLogger->log('auth_provider.updated', $request->user(), $authProvider, [
            'auth_provider_id' => $authProvider->id,
            'before' => $before,
            'after' => $authProvider->only(['provider', 'name', 'client_id', 'scopes', 'allowed_domains', 'tenant', 'is_enabled']),
            'secret_rotated' => $request->filled('client_secret'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Auth provider updated.']);

        return redirect()->route('auth-providers.index');
    }

    public function destroy(AuthProvider $authProvider, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless(request()->user()->isAdmin(), 403);

        if ($authProvider->userIdentities()->exists()) {
            return back()->withErrors([
                'auth_provider' => 'Provider cannot be deleted while user identities are linked. Disable it instead.',
            ]);
        }

        $provider = $authProvider->provider->value;
        $authProviderId = $authProvider->id;
        $authProvider->delete();

        $auditLogger->log('auth_provider.deleted', request()->user(), $authProvider, [
            'auth_provider_id' => $authProviderId,
            'provider' => $provider,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Auth provider deleted.']);

        return redirect()->route('auth-providers.index');
    }

    /**
     * @return array{id: int, provider: string, provider_label: string, name: string, client_id: string, scopes: string, allowed_domains: string, tenant: string|null, is_enabled: bool, callback_url: string, user_identities_count?: int}
     */
    private function providerPayload(AuthProvider $authProvider): array
    {
        return [
            'id' => $authProvider->id,
            'provider' => $authProvider->provider->value,
            'provider_label' => $authProvider->provider->label(),
            'name' => $authProvider->name,
            'client_id' => $authProvider->client_id,
            'scopes' => implode(' ', $authProvider->effectiveScopes()),
            'allowed_domains' => implode(', ', $authProvider->allowed_domains ?: []),
            'tenant' => $authProvider->tenant,
            'is_enabled' => $authProvider->is_enabled,
            'callback_url' => $authProvider->callbackUrl(),
            'user_identities_count' => $authProvider->user_identities_count ?? null,
        ];
    }

    /**
     * @return array<int, array{value: string, label: string, default_scopes: string, setup_guide: array{overview: string, steps: array<int, string>, documentation_url: string, documentation_label: string}}>
     */
    private function providerOptions(): array
    {
        return array_map(fn (AuthProviderType $provider): array => [
            'value' => $provider->value,
            'label' => $provider->label(),
            'default_scopes' => implode(' ', $provider->defaultScopes()),
            'setup_guide' => $provider->setupGuide(),
        ], AuthProviderType::cases());
    }
}
