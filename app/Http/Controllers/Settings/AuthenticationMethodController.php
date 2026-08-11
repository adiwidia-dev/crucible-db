<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAuthenticationMethodsRequest;
use App\Models\AuthProvider;
use App\Services\ApplicationSettings;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticationMethodController extends Controller
{
    public function edit(ApplicationSettings $settings): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return Inertia::render('settings/admin/authentication', [
            'methods' => [
                'password_login_enabled' => $settings->passwordLoginEnabled(),
                'passkey_login_enabled' => $settings->passkeyLoginEnabled(),
            ],
            'enabled_provider_count' => AuthProvider::query()->where('is_enabled', true)->count(),
            'configured_sso_providers' => AuthProvider::query()
                ->orderBy('name')
                ->get()
                ->map(fn (AuthProvider $authProvider): array => [
                    'id' => $authProvider->id,
                    'name' => $authProvider->name,
                    'provider_label' => $authProvider->provider->label(),
                    'is_enabled' => $authProvider->is_enabled,
                ])
                ->all(),
        ]);
    }

    public function update(UpdateAuthenticationMethodsRequest $request, ApplicationSettings $settings, AuditLogger $auditLogger): RedirectResponse
    {
        $before = [
            'password_login_enabled' => $settings->passwordLoginEnabled(),
            'passkey_login_enabled' => $settings->passkeyLoginEnabled(),
        ];
        $settings->put($request->validated());

        $auditLogger->log('authentication_methods.updated', $request->user(), null, [
            'before' => $before,
            'after' => [
                'password_login_enabled' => $settings->passwordLoginEnabled(),
                'passkey_login_enabled' => $settings->passkeyLoginEnabled(),
            ],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Authentication methods updated.']);

        return back();
    }
}
