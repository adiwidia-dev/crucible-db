<?php

namespace App\Http\Middleware;

use App\Services\ApplicationSettings;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $settings = app(ApplicationSettings::class);

        return [
            ...parent::share($request),
            'name' => $settings->appName(),
            'auth' => [
                'user' => $request->user()?->loadMissing('roles'),
            ],
            'notification_summary' => fn (): array => [
                'unread_count' => $request->user()?->unreadNotifications()->count() ?? 0,
                'recent' => $request->user()
                    ?->notifications()
                    ->latest()
                    ->limit(5)
                    ->get()
                    ->map(fn (DatabaseNotification $notification): array => [
                        'id' => $notification->id,
                        'severity' => $notification->data['severity'] ?? 'info',
                        'title' => $notification->data['title'] ?? 'Operational notification',
                        'message' => $notification->data['message'] ?? '',
                        'url' => $notification->data['url'] ?? route('dashboard'),
                        'read_at' => $notification->read_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all() ?? [],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
