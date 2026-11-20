<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SettingsRedirectTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('legacySettingsUrls')]
    public function test_legacy_settings_urls_redirect_with_get_routes(string $source, string $destination): void
    {
        $admin = User::factory()
            ->withRole(Role::factory()->admin()->create())
            ->create();

        $this->actingAs($admin)
            ->get($source)
            ->assertRedirect($destination);

        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route): bool => $route->uri() === ltrim($source, '/'));

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    /**
     * @return array<string, array{source: string, destination: string}>
     */
    public static function legacySettingsUrls(): array
    {
        return [
            'settings index' => [
                'source' => '/settings',
                'destination' => '/settings/profile',
            ],
            'appearance settings' => [
                'source' => '/settings/appearance',
                'destination' => '/settings/preferences',
            ],
            'notification settings' => [
                'source' => '/settings/notifications',
                'destination' => '/settings/preferences',
            ],
            'authentication providers' => [
                'source' => '/auth-providers',
                'destination' => '/settings/admin/authentication-providers',
            ],
            'old authentication providers path' => [
                'source' => '/settings/authentication-providers',
                'destination' => '/settings/admin/authentication-providers',
            ],
            'users' => [
                'source' => '/users',
                'destination' => '/settings/admin/users',
            ],
            'roles' => [
                'source' => '/roles',
                'destination' => '/settings/admin/roles',
            ],
            'connection groups' => [
                'source' => '/connection-groups',
                'destination' => '/settings/admin/connection-groups',
            ],
            'audit logs' => [
                'source' => '/audit-logs',
                'destination' => '/settings/admin/audit-logs',
            ],
        ];
    }
}
