<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Models\DatabaseConnection;
use App\Models\Role;
use App\Models\User;
use App\Notifications\OperationalNotification;
use App\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_administrators_cannot_view_or_update_notification_policy(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('notification-settings.edit'))->assertForbidden();
        $this->actingAs($user)->patch(route('notification-settings.update'), [
            'notifications_in_app_enabled' => true,
            'notifications_email_enabled' => true,
            'notifications_review_enabled' => true,
            'notifications_execution_completed_enabled' => true,
            'notifications_execution_failed_enabled' => true,
            'notifications_query_access_enabled' => true,
            'notifications_connection_failed_enabled' => true,
        ])->assertForbidden();
    }

    public function test_admin_can_update_notification_policy_and_operational_recipients(): void
    {
        $role = Role::factory()->admin()->create();
        $admin = User::factory()->withRole($role)->create();
        $secondaryAdmin = User::factory()->withRole($role)->create();

        $this->actingAs($admin)->get(route('notification-settings.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/admin/notifications')
                ->has('administrators', 2),
            );

        $this->actingAs($admin)->patch(route('notification-settings.update'), [
            'notifications_in_app_enabled' => true,
            'notifications_email_enabled' => true,
            'notifications_review_enabled' => true,
            'notifications_execution_completed_enabled' => false,
            'notifications_execution_failed_enabled' => true,
            'notifications_query_access_enabled' => true,
            'notifications_connection_failed_enabled' => true,
            'operational_recipient_ids' => [$secondaryAdmin->id],
        ])->assertRedirect();

        $this->assertTrue($secondaryAdmin->refresh()->is_operational_alert_recipient);
        $this->assertFalse($admin->refresh()->is_operational_alert_recipient);
        $this->assertSame('0', ApplicationSetting::query()
            ->where('key', 'notifications_execution_completed_enabled')
            ->firstOrFail()
            ->value);
    }

    public function test_user_can_update_email_notification_preferences(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('preferences.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('settings/preferences'));

        $this->actingAs($user)->patch(route('user-notifications.update'), [
            'email_approvals' => true,
            'email_execution_completed' => true,
            'email_execution_failed' => false,
            'email_sessions' => true,
            'email_connection_failed' => false,
        ])->assertRedirect();

        $preferences = $user->refresh()->notification_preferences;

        $this->assertTrue($preferences['email']['approvals']);
        $this->assertTrue($preferences['email']['execution_completed']);
        $this->assertFalse($preferences['email']['execution_failed']);
        $this->assertTrue($preferences['email']['sessions']);
        $this->assertFalse($preferences['email']['connection_failed']);
    }

    public function test_notification_inbox_only_marks_the_current_users_notifications_as_read(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $notification = $this->createNotification($user);
        $otherNotification = $this->createNotification($otherUser);

        $this->actingAs($user)->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('notifications/index')
                ->has('notifications.data', 1)
                ->where('notification_summary.unread_count', 1)
                ->has('notification_summary.recent', 1)
                ->where('notification_summary.recent.0.id', $notification->id),
            );

        $this->actingAs($user)
            ->patch(route('notifications.read', $notification))
            ->assertRedirect();

        $this->assertNotNull($notification->refresh()->read_at);
        $this->assertNull($otherNotification->refresh()->read_at);
    }

    public function test_user_can_mark_all_of_their_notifications_as_read(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $firstNotification = $this->createNotification($user);
        $secondNotification = $this->createNotification($user);
        $otherNotification = $this->createNotification($otherUser);

        $this->actingAs($user)
            ->patch(route('notifications.read-all'))
            ->assertRedirect();

        $this->assertNotNull($firstNotification->refresh()->read_at);
        $this->assertNotNull($secondNotification->refresh()->read_at);
        $this->assertNull($otherNotification->refresh()->read_at);
    }

    public function test_failed_connection_test_notifies_the_creator_and_operational_administrators(): void
    {
        Notification::fake();

        $creator = User::factory()->create();
        $operationalAdmin = $this->adminUser();
        $operationalAdmin->update(['is_operational_alert_recipient' => true]);
        $connection = DatabaseConnection::factory()->create([
            'created_by_id' => $creator->id,
            'name' => 'Production orders',
        ]);

        app(NotificationDispatcher::class)->connectionTestFailed($connection, $creator);

        Notification::assertSentTo(
            [$creator, $operationalAdmin],
            OperationalNotification::class,
        );
    }

    private function adminUser(): User
    {
        $role = Role::factory()->admin()->create();

        return User::factory()->withRole($role)->create();
    }

    private function createNotification(User $user): DatabaseNotification
    {
        /** @var DatabaseNotification $notification */
        $notification = $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => OperationalNotification::class,
            'data' => [
                'event' => 'query_request.execution_failed',
                'severity' => 'critical',
                'title' => 'Batch execution failed',
                'message' => 'DEP-42 failed at statement 2.',
                'action_label' => 'Open request',
                'url' => route('dashboard'),
            ],
        ]);

        return $notification;
    }
}
