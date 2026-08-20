<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNotificationPreferencesRequest;
use App\Models\DatabaseConnection;
use App\Models\NotificationSubscription;
use App\Models\QueryRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferencesController extends Controller
{
    public function edit(): Response
    {
        /** @var User $user */
        $user = request()->user();
        $preferences = $user->notification_preferences ?? [];

        return Inertia::render('settings/notifications', [
            'preferences' => [
                'email_approvals' => data_get($preferences, 'email.approvals', false),
                'email_execution_completed' => data_get($preferences, 'email.execution_completed', false),
                'email_execution_failed' => data_get($preferences, 'email.execution_failed', true),
                'email_sessions' => data_get($preferences, 'email.sessions', false),
                'email_connection_failed' => data_get($preferences, 'email.connection_failed', true),
            ],
            'subscriptions' => $user->notificationSubscriptions()
                ->with('subscribable')
                ->latest()
                ->get()
                ->map(fn (NotificationSubscription $subscription): ?array => $this->subscriptionSummary($subscription, $user))
                ->filter()
                ->values(),
        ]);
    }

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

    /**
     * @return array{id:int,type:'query_request'|'database_connection',subscribable_id:int,title:string,detail:string}|null
     */
    private function subscriptionSummary(NotificationSubscription $subscription, User $user): ?array
    {
        $subscribable = $subscription->subscribable;

        if ($subscribable instanceof QueryRequest && $user->can('view', $subscribable)) {
            return [
                'id' => $subscription->id,
                'type' => 'query_request',
                'subscribable_id' => $subscribable->id,
                'title' => $subscribable->title,
                'detail' => 'Query request updates',
            ];
        }

        if ($subscribable instanceof DatabaseConnection && $user->can('view', $subscribable)) {
            return [
                'id' => $subscription->id,
                'type' => 'database_connection',
                'subscribable_id' => $subscribable->id,
                'title' => $subscribable->name,
                'detail' => 'Connection health updates',
            ];
        }

        return null;
    }
}
