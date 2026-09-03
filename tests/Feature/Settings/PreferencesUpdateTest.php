<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferencesUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferences_page_includes_the_users_timezone_and_available_timezones(): void
    {
        $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);

        $this->actingAs($user)
            ->get(route('preferences.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/preferences')
                ->where('timezone', 'Asia/Jakarta')
                ->where('timezones', fn ($timezones): bool => $timezones->contains('UTC')));
    }

    public function test_user_can_update_their_timezone_preference(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $this->actingAs($user)
            ->patch(route('preferences.timezone.update'), [
                'timezone' => 'Asia/Jakarta',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('preferences.edit'));

        $this->assertSame('Asia/Jakarta', $user->refresh()->timezone);
    }

    public function test_timezone_preference_must_be_valid(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $this->actingAs($user)
            ->patch(route('preferences.timezone.update'), [
                'timezone' => 'Jakarta',
            ])
            ->assertSessionHasErrors('timezone');

        $this->assertSame('UTC', $user->refresh()->timezone);
    }
}
