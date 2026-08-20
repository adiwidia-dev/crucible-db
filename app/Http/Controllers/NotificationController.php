<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('notifications/index', [
            'notifications' => $request->user()
                ->notifications()
                ->latest()
                ->paginate(30)
                ->through(fn (DatabaseNotification $notification): array => [
                    'id' => $notification->id,
                    'event' => $notification->data['event'] ?? 'system',
                    'severity' => $notification->data['severity'] ?? 'info',
                    'title' => $notification->data['title'] ?? 'Operational notification',
                    'message' => $notification->data['message'] ?? '',
                    'action_label' => $notification->data['action_label'] ?? 'Open',
                    'url' => $notification->data['url'] ?? route('dashboard'),
                    'created_at' => $notification->created_at?->toIso8601String(),
                    'read_at' => $notification->read_at?->toIso8601String(),
                ]),
        ]);
    }

    public function markRead(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        abort_unless(
            $notification->notifiable_type === $request->user()::class
            && (int) $notification->notifiable_id === $request->user()->id,
            404,
        );

        $notification->markAsRead();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
