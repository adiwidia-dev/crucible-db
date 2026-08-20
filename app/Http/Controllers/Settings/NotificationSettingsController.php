<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationSettingsRequest;
use App\Models\User;
use App\Services\ApplicationSettings;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    public function edit(ApplicationSettings $settings): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return Inertia::render('settings/admin/notifications', [
            'settings' => $settings->notificationFormValues(),
            'administrators' => User::query()
                ->whereNull('disabled_at')
                ->whereHas('roles', fn ($roles) => $roles->where('is_admin', true))
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'is_operational_alert_recipient'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_operational_alert_recipient' => $user->is_operational_alert_recipient,
                ]),
        ]);
    }

    public function update(UpdateNotificationSettingsRequest $request, ApplicationSettings $settings, AuditLogger $auditLogger): RedirectResponse
    {
        $before = $settings->notificationFormValues();
        $values = $request->safe()->except('operational_recipient_ids');
        $recipientIds = $request->validated('operational_recipient_ids', []);

        DB::transaction(function () use ($recipientIds, $settings, $values): void {
            $settings->put($values);

            User::query()
                ->where('is_operational_alert_recipient', true)
                ->update(['is_operational_alert_recipient' => false]);

            if ($recipientIds !== []) {
                User::query()
                    ->whereKey($recipientIds)
                    ->update(['is_operational_alert_recipient' => true]);
            }
        });

        $auditLogger->log('notification_settings.updated', $request->user(), null, [
            'before' => $before,
            'after' => $settings->notificationFormValues(),
            'operational_recipient_ids' => $recipientIds,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Notification policy updated.']);

        return back();
    }
}
