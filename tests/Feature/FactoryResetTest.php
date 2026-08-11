<?php

namespace Tests\Feature;

use App\Http\Requests\ConfirmFactoryResetRequest;
use App\Models\ApplicationSetting;
use App\Models\AuditLog;
use App\Models\AuthProvider;
use App\Models\DatabaseConnection;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactoryResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_administrators_can_factory_reset_crucible(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete(route('application-settings.factory-reset'), [
                'confirmation' => ConfirmFactoryResetRequest::ConfirmationPhrase,
            ])
            ->assertForbidden();
    }

    public function test_factory_reset_requires_the_exact_confirmation_phrase(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->from(route('application-settings.edit'))
            ->delete(route('application-settings.factory-reset'), [
                'confirmation' => 'reset',
            ])
            ->assertRedirect(route('application-settings.edit'))
            ->assertSessionHasErrors('confirmation');

        $this->assertModelExists($admin);
    }

    public function test_factory_reset_removes_control_plane_data_and_restarts_web_setup(): void
    {
        $admin = $this->administrator();
        $otherUser = User::factory()->create();

        DatabaseConnection::factory()->create(['created_by_id' => $admin->id]);
        AuthProvider::factory()->create();
        ApplicationSetting::factory()->create(['key' => 'app_name', 'value' => 'Before reset']);
        AuditLog::factory()->create(['actor_id' => $otherUser->id]);

        $this->actingAs($admin)
            ->delete(route('application-settings.factory-reset'), [
                'confirmation' => ConfirmFactoryResetRequest::ConfirmationPhrase,
            ])
            ->assertRedirect(route('setup.show'));

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Role::query()->count());
        $this->assertSame(0, DatabaseConnection::query()->count());
        $this->assertSame(0, AuthProvider::query()->count());
        $this->assertSame(0, ApplicationSetting::query()->count());
        $this->assertSame(0, AuditLog::query()->count());

        $this->get(route('setup.show'))->assertOk();
    }

    private function administrator(): User
    {
        $role = Role::factory()->admin()->create();

        return User::factory()->withRole($role)->create();
    }
}
