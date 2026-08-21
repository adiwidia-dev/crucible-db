<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNotificationPreferencesRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class NotificationPreferencesController extends Controller
{
    public function update(UpdateNotificationPreferencesRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'notification_preferences' => [
                'email' => [
                    'approvals' => $request->boolean('email_approvals'),
                    'execution_completed' => $request->boolean('email_execution_completed'),
                    'execution_failed' => $request->boolean('email_execution_failed'),
                    'sessions' => $request->boolean('email_sessions'),
                    'connection_failed' => $request->boolean('email_connection_failed'),
                ],
            ],
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Notification preferences updated.']);

        return back();
    }
}
