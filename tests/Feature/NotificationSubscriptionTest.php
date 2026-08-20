<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Models\DatabaseConnection;
use App\Models\NotificationSubscription;
use App\Models\QueryRequest;
use App\Models\Role;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use App\Notifications\OperationalNotification;
use App\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_watch_and_stop_watching_a_query_request(): void
    {
        [$user, $connection] = $this->userWithConnectionAccess();
        $queryRequest = QueryRequest::factory()->create([
            'database_connection_id' => $connection->id,
            'requester_id' => User::factory(),
        ]);

        $this->actingAs($user)
            ->post(route('query-requests.subscription.store', $queryRequest))
            ->assertRedirect();

        $this->assertDatabaseHas('notification_subscriptions', [
            'user_id' => $user->id,
            'subscribable_type' => QueryRequest::class,
            'subscribable_id' => $queryRequest->id,
        ]);

        $this->actingAs($user)
            ->post(route('query-requests.subscription.store', $queryRequest))
            ->assertRedirect();

        $this->assertDatabaseCount('notification_subscriptions', 1);

        $this->actingAs($user)
            ->delete(route('query-requests.subscription.destroy', $queryRequest))
            ->assertRedirect();

        $this->assertDatabaseMissing('notification_subscriptions', [
            'user_id' => $user->id,
            'subscribable_type' => QueryRequest::class,
            'subscribable_id' => $queryRequest->id,
        ]);
    }

    public function test_user_can_watch_connection_health_and_manage_watches_from_notification_preferences(): void
    {
        [$user, $connection] = $this->userWithConnectionAccess();

        $this->actingAs($user)
            ->post(route('connections.subscription.store', $connection))
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('user-notifications.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/notifications')
                ->has('subscriptions', 1)
                ->where('subscriptions.0.type', 'database_connection')
                ->where('subscriptions.0.title', $connection->name),
            );

        $this->actingAs($user)
            ->delete(route('connections.subscription.destroy', $connection))
            ->assertRedirect();

        $this->assertDatabaseCount('notification_subscriptions', 0);
    }

    public function test_subscribers_receive_safe_request_and_connection_notifications(): void
    {
        Notification::fake();

        [$subscriber, $connection] = $this->userWithConnectionAccess();
        $requester = User::factory()->create();
        $queryRequest = QueryRequest::factory()->create([
            'database_connection_id' => $connection->id,
            'requester_id' => $requester->id,
            'title' => 'DEP-908 customer migration',
        ]);
        NotificationSubscription::factory()->create([
            'user_id' => $subscriber->id,
            'subscribable_type' => QueryRequest::class,
            'subscribable_id' => $queryRequest->id,
        ]);
        NotificationSubscription::factory()->create([
            'user_id' => $subscriber->id,
            'subscribable_type' => DatabaseConnection::class,
            'subscribable_id' => $connection->id,
        ]);

        $dispatcher = app(NotificationDispatcher::class);
        $dispatcher->batchCompleted($queryRequest);
        $dispatcher->connectionTestFailed($connection, $requester);

        $this->assertCount(
            2,
            Notification::sent($subscriber, OperationalNotification::class),
        );
    }

    public function test_user_cannot_watch_resources_outside_their_access_scope(): void
    {
        $user = User::factory()->create();
        $connection = DatabaseConnection::factory()->create();
        $queryRequest = QueryRequest::factory()->create([
            'database_connection_id' => $connection->id,
        ]);

        $this->actingAs($user)
            ->post(route('query-requests.subscription.store', $queryRequest))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('connections.subscription.store', $connection))
            ->assertForbidden();
    }

    /**
     * @return array{User, DatabaseConnection}
     */
    private function userWithConnectionAccess(): array
    {
        $role = Role::factory()->developer()->create();
        $user = User::factory()->withRole($role)->create();
        $connection = DatabaseConnection::factory()->create();

        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'requires_approval' => false,
        ]);

        return [$user, $connection];
    }
}
