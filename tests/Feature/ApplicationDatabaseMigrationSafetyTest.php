<?php

namespace Tests\Feature;

use App\Enums\NativeProxyLeaseStatus;
use App\Models\NativeProxyConnection;
use App\Models\NativeProxyLease;
use App\Models\QuerySession;
use App\Services\ApplicationDatabaseMigrationFence;
use App\Services\ApplicationDatabaseMigrationSafety;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class ApplicationDatabaseMigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/application-database-safety-'.bin2hex(random_bytes(8)));
        config([
            'database.control_metadata.migration_directory' => $this->directory.'/plans',
            'database.control_metadata.migration_fence_path' => $this->directory.'/migration.fence',
            'queue.default' => 'sync',
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_fence_blocks_web_native_control_and_new_queued_work(): void
    {
        app(ApplicationDatabaseMigrationFence::class)->engage('01K4A1B2C3D4E5F6G7H8J9K0MN');

        $this->get('/login')->assertServiceUnavailable();
        $this->getJson(route('internal.native-proxy.health'))->assertServiceUnavailable();

        $this->expectException(RuntimeException::class);
        Event::dispatch(new JobQueueing('sync', 'default', 'TestJob', '{}', null));
    }

    public function test_safety_refuses_active_sessions_and_releases_the_fence(): void
    {
        QuerySession::factory()->create([
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addMinutes(10),
            'ended_at' => null,
        ]);

        try {
            app(ApplicationDatabaseMigrationSafety::class)->engage('01K4A1B2C3D4E5F6G7H8J9K0MN', 0);
            $this->fail('An active query session should prevent migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot migrate while access is active', $exception->getMessage());
            $this->assertFalse(app(ApplicationDatabaseMigrationFence::class)->isActive());
        }
    }

    public function test_safety_refuses_an_active_native_lease_even_after_its_browser_session_ends(): void
    {
        NativeProxyLease::factory()->active()->create();
        QuerySession::query()->update([
            'expires_at' => now()->subMinute(),
            'ended_at' => now(),
        ]);

        $activity = app(ApplicationDatabaseMigrationSafety::class)->activity();
        $this->assertSame(0, $activity['query_sessions']);
        $this->assertSame(1, $activity['native_leases']);

        try {
            app(ApplicationDatabaseMigrationSafety::class)->engage('01K4A1B2C3D4E5F6G7H8J9K0MN', 0);
            $this->fail('An active native lease should prevent migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('1 native leases', $exception->getMessage());
            $this->assertFalse(app(ApplicationDatabaseMigrationFence::class)->isActive());
        }
    }

    public function test_safety_refuses_an_active_native_connection_even_after_other_access_ends(): void
    {
        NativeProxyConnection::factory()->active()->create();
        QuerySession::query()->update([
            'expires_at' => now()->subMinute(),
            'ended_at' => now(),
        ]);
        NativeProxyLease::query()->update([
            'status' => NativeProxyLeaseStatus::Revoked->value,
            'expires_at' => now()->subMinute(),
            'revoked_at' => now(),
        ]);

        $activity = app(ApplicationDatabaseMigrationSafety::class)->activity();
        $this->assertSame(0, $activity['query_sessions']);
        $this->assertSame(0, $activity['native_leases']);
        $this->assertSame(1, $activity['native_connections']);

        try {
            app(ApplicationDatabaseMigrationSafety::class)->engage('01K4A1B2C3D4E5F6G7H8J9K0MN', 0);
            $this->fail('An active native connection should prevent migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('1 native connections', $exception->getMessage());
            $this->assertFalse(app(ApplicationDatabaseMigrationFence::class)->isActive());
        }
    }

    public function test_queued_work_must_drain_before_the_fence_can_remain_engaged(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert([
            'queue' => 'queries',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        try {
            app(ApplicationDatabaseMigrationSafety::class)->engage('01K4A1B2C3D4E5F6G7H8J9K0MN', 0);
            $this->fail('Queued work should prevent migration when the drain timeout elapses.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('did not drain', $exception->getMessage());
            $this->assertFalse(app(ApplicationDatabaseMigrationFence::class)->isActive());
        }
    }
}
