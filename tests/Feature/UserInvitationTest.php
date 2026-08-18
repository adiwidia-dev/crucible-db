<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Support\SessionKey;
use Tests\TestCase;

class UserInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_invite_a_user_without_granting_roles(): void
    {
        Notification::fake();

        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->post(route('users.store'), [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
        ]);

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

        $response
            ->assertRedirect(route('users.index'))
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => [
                    'type' => 'success',
                    'message' => 'User invitation sent.',
                ],
            ]);

        $this->assertSame('Ada', $user->first_name);
        $this->assertSame('Lovelace', $user->last_name);
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->role_id);
        $this->assertNotNull($user->invited_at);
        $this->assertNotNull($user->invitation_token_hash);
        $this->assertSame(0, $user->roles()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.invited',
            'auditable_id' => $user->id,
        ]);

        Notification::assertSentTo($user, UserInvitationNotification::class);
    }

    public function test_invited_users_receive_the_application_default_timezone(): void
    {
        Notification::fake();
        $admin = $this->adminUser();
        ApplicationSetting::factory()->create([
            'key' => 'default_timezone',
            'value' => 'Asia/Jakarta',
        ]);

        $this->actingAs($admin)->post(route('users.store'), [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'Asia/Jakarta',
            User::query()->where('email', 'ada@example.com')->value('timezone'),
        );
    }

    public function test_non_admin_cannot_invite_users(): void
    {
        $developer = User::factory()->create();

        $this->actingAs($developer)->get(route('users.create'))->assertForbidden();
        $this->actingAs($developer)->post(route('users.store'), [
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace@example.com',
        ])->assertForbidden();
    }

    public function test_invited_user_can_accept_invitation_and_set_password(): void
    {
        $token = 'valid-invitation-token';
        $user = User::factory()->unverified()->create([
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'name' => 'Grace Hopper',
            'invited_at' => now(),
            'invitation_token_hash' => hash('sha256', $token),
        ]);
        $url = (new UserInvitationNotification($token))->invitationUrl($user);

        $this->get($url)->assertOk();

        $response = $this->post($url, [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $user->refresh();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->invitation_accepted_at);
        $this->assertNull($user->invitation_token_hash);
        $this->assertSame(0, $user->roles()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.invitation_accepted',
            'auditable_id' => $user->id,
        ]);
    }

    public function test_invitation_cannot_be_accepted_with_invalid_token(): void
    {
        $user = User::factory()->unverified()->create([
            'invited_at' => now(),
            'invitation_token_hash' => hash('sha256', 'valid-token'),
        ]);
        $url = (new UserInvitationNotification('invalid-token'))->invitationUrl($user);

        $this->get($url)->assertForbidden();
        $this->post($url, [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertForbidden();
    }

    private function adminUser(): User
    {
        $role = Role::factory()->admin()->create();

        return User::factory()->withRole($role)->create();
    }
}
