<?php

namespace App\Http\Controllers;

use App\Models\DatabaseConnection;
use App\Models\NotificationSubscription;
use App\Models\QueryRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class NotificationSubscriptionController extends Controller
{
    public function storeQueryRequest(QueryRequest $queryRequest, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('view', $queryRequest);

        $created = $this->subscribe(request()->user(), $queryRequest);

        if ($created) {
            $auditLogger->log('query_request.notifications_subscribed', request()->user(), $queryRequest);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Watching request updates.']);

        return back();
    }

    public function destroyQueryRequest(QueryRequest $queryRequest, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('view', $queryRequest);

        $deleted = $this->unsubscribe(request()->user(), $queryRequest);

        if ($deleted) {
            $auditLogger->log('query_request.notifications_unsubscribed', request()->user(), $queryRequest);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Stopped watching request updates.']);

        return back();
    }

    public function storeDatabaseConnection(DatabaseConnection $databaseConnection, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('view', $databaseConnection);

        $created = $this->subscribe(request()->user(), $databaseConnection);

        if ($created) {
            $auditLogger->log('database_connection.notifications_subscribed', request()->user(), $databaseConnection);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Watching connection health updates.']);

        return back();
    }

    public function destroyDatabaseConnection(DatabaseConnection $databaseConnection, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('view', $databaseConnection);

        $deleted = $this->unsubscribe(request()->user(), $databaseConnection);

        if ($deleted) {
            $auditLogger->log('database_connection.notifications_unsubscribed', request()->user(), $databaseConnection);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Stopped watching connection health updates.']);

        return back();
    }

    private function subscribe(User $user, Model $subscribable): bool
    {
        $subscription = NotificationSubscription::query()->firstOrCreate([
            'user_id' => $user->id,
            'subscribable_type' => $subscribable->getMorphClass(),
            'subscribable_id' => $subscribable->getKey(),
        ]);

        return $subscription->wasRecentlyCreated;
    }

    private function unsubscribe(User $user, Model $subscribable): bool
    {
        return NotificationSubscription::query()
            ->whereBelongsTo($user)
            ->whereMorphedTo('subscribable', $subscribable)
            ->delete() > 0;
    }
}
