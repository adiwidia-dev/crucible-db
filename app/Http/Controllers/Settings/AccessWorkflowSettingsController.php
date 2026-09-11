<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AccessMode;
use App\Enums\AccessTransport;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAccessWorkflowSettingsRequest;
use App\Models\NativeProxyLease;
use App\Models\QuerySession;
use App\Models\RoleConnectionGroupPolicy;
use App\Models\RoleDatabasePermission;
use App\Services\ApplicationSettings;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AccessWorkflowSettingsController extends Controller
{
    public function edit(ApplicationSettings $settings): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return Inertia::render('settings/admin/access-workflows', [
            'settings' => $settings->accessWorkflowFormValues(),
            'usage' => [
                'active_query_sessions' => QuerySession::query()
                    ->whereNull('ended_at')
                    ->where('expires_at', '>', now())
                    ->whereHas('queryRequest', fn ($query) => $query
                        ->where('access_transport', AccessTransport::Browser->value))
                    ->count(),
                'active_native_sessions' => NativeProxyLease::query()->active()->count(),
                'query_role_grants' => RoleDatabasePermission::query()
                    ->where('query_access_mode', '!=', AccessMode::None->value)
                    ->count()
                    + RoleConnectionGroupPolicy::query()
                        ->where('query_access_mode', '!=', AccessMode::None->value)
                        ->count(),
                'native_role_grants' => RoleDatabasePermission::query()
                    ->where('native_proxy_access_mode', '!=', AccessMode::None->value)
                    ->count()
                    + RoleConnectionGroupPolicy::query()
                        ->where('native_proxy_access_mode', '!=', AccessMode::None->value)
                        ->count(),
            ],
        ]);
    }

    public function update(
        UpdateAccessWorkflowSettingsRequest $request,
        ApplicationSettings $settings,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $before = $settings->accessWorkflowFormValues();

        $settings->put($request->validated());

        $auditLogger->log('access_workflows.updated', $request->user(), null, [
            'before' => $before,
            'after' => $settings->accessWorkflowFormValues(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Access settings updated.']);

        return back();
    }
}
