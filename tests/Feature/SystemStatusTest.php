<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\SystemStatus;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use RuntimeException;
use Tests\TestCase;

class SystemStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_administrators_can_view_system_status(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('system-status.show'))
            ->assertForbidden();
    }

    public function test_administrators_can_view_cached_system_status_and_application_metadata(): void
    {
        $admin = $this->administrator();

        Cache::put('system-status:latest', [
            'checked_at' => '2026-09-18T00:00:00+00:00',
            'components' => [
                'database' => ['status' => 'healthy', 'detail' => 'Database is reachable.', 'metadata' => []],
                'redis' => ['status' => 'healthy', 'detail' => 'Redis is reachable.', 'metadata' => []],
                'horizon' => ['status' => 'healthy', 'detail' => 'Horizon is active.', 'metadata' => ['masters' => 1, 'supervisors' => 4]],
                'scheduler' => ['status' => 'healthy', 'detail' => 'Scheduler is active.', 'metadata' => ['last_heartbeat_at' => '2026-09-18T00:00:00+00:00']],
                'native_proxy' => ['status' => 'healthy', 'detail' => 'Native proxy is ready.', 'metadata' => ['proxy_id' => 'proxy-1', 'version' => '0.2.8']],
            ],
        ], now()->addMinute());

        $this->actingAs($admin)
            ->get(route('system-status.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/admin/system-status')
                ->where('system_status.checked_at', '2026-09-18T00:00:00+00:00')
                ->where('system_status.components.horizon.metadata.supervisors', 4)
                ->where('application_runtime.metadata.version', 'v0.2.8')
                ->missing('wayfinder'));
    }

    public function test_refresh_caches_health_and_reports_paused_horizon_as_degraded(): void
    {
        $this->mock(RedisFactory::class)->shouldReceive('connection->ping')->once()->andReturn('PONG');
        $this->mock(MasterSupervisorRepository::class)->shouldReceive('all')->once()
            ->andReturn([(object) ['status' => 'running']]);
        $this->mock(SupervisorRepository::class)->shouldReceive('all')->once()
            ->andReturn([(object) ['status' => 'paused']]);

        $status = app(SystemStatus::class);
        $status->recordSchedulerHeartbeat();
        $snapshot = $status->refresh();

        $this->assertSame('healthy', $snapshot['components']['database']['status']);
        $this->assertSame('healthy', $snapshot['components']['redis']['status']);
        $this->assertSame('degraded', $snapshot['components']['horizon']['status']);
        $this->assertSame('healthy', $snapshot['components']['scheduler']['status']);
        $this->assertSame('disabled', $snapshot['components']['native_proxy']['status']);
        $this->assertSame($snapshot, $status->latest());

        $this->travel(4)->minutes();

        $this->assertNull($status->latest()['checked_at']);
        $this->assertSame('unknown', $status->latest()['components']['database']['status']);
    }

    public function test_refresh_reports_failures_without_exposing_exception_details_or_inventing_a_heartbeat(): void
    {
        $this->mock(RedisFactory::class)->shouldReceive('connection->ping')->once()
            ->andThrow(new RuntimeException('sensitive-redis-connection-string'));
        $this->mock(MasterSupervisorRepository::class)->shouldReceive('all')->once()->andReturn([]);
        $this->mock(SupervisorRepository::class)->shouldReceive('all')->once()->andReturn([]);

        $snapshot = app(SystemStatus::class)->refresh();

        $this->assertSame('unhealthy', $snapshot['components']['redis']['status']);
        $this->assertStringNotContainsString('sensitive-redis-connection-string', $snapshot['components']['redis']['detail']);
        $this->assertSame('unhealthy', $snapshot['components']['horizon']['status']);
        $this->assertSame('unhealthy', $snapshot['components']['scheduler']['status']);
        $this->assertNull($snapshot['components']['scheduler']['metadata']['last_heartbeat_at']);
    }

    private function administrator(): User
    {
        $role = Role::factory()->admin()->create();

        return User::factory()->withRole($role)->create();
    }
}
