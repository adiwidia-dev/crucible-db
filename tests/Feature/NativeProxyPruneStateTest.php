<?php

namespace Tests\Feature;

use App\Enums\ExecutionStatus;
use App\Enums\NativeProxyConnectionStatus;
use App\Enums\NativeProxyDeviceAuthorizationStatus;
use App\Models\NativeProxyConnection;
use App\Models\NativeProxyDeviceAuthorization;
use App\Models\QueryExecution;
use App\Models\QuerySessionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NativeProxyPruneStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_reconciles_stale_native_proxy_state_and_removes_expired_retained_rows(): void
    {
        config([
            'native_proxy.connection_stale_seconds' => 60,
            'native_proxy.statement_stale_seconds' => 60,
        ]);
        $expiredAuthorization = NativeProxyDeviceAuthorization::factory()->create([
            'status' => NativeProxyDeviceAuthorizationStatus::Pending,
            'expires_at' => now()->subSecond(),
        ]);
        $staleConnection = NativeProxyConnection::factory()->active()->create([
            'last_activity_at' => now()->subSeconds(61),
        ]);
        $staleSessionQuery = QuerySessionQuery::factory()->create([
            'status' => ExecutionStatus::Running,
            'started_at' => now()->subSeconds(61),
            'finished_at' => null,
        ]);
        $staleExecution = QueryExecution::factory()->create([
            'status' => ExecutionStatus::Running,
            'started_at' => now()->subSeconds(61),
            'finished_at' => null,
        ]);
        $expiredReservation = NativeProxyConnection::factory()->create([
            'status' => NativeProxyConnectionStatus::Reserved,
            'reservation_expires_at' => now()->subSecond(),
        ]);

        $this->artisan('crucible:prune-native-proxy-state')->assertSuccessful();

        $this->assertSame(NativeProxyDeviceAuthorizationStatus::Expired, $expiredAuthorization->fresh()->status);
        $this->assertDatabaseHas('native_proxy_connections', [
            'id' => $staleConnection->id,
            'status' => NativeProxyConnectionStatus::Failed->value,
            'disconnect_reason' => 'Native proxy connection heartbeat timed out.',
        ]);
        $this->assertDatabaseHas('native_proxy_connections', [
            'id' => $expiredReservation->id,
            'status' => NativeProxyConnectionStatus::Failed->value,
            'disconnect_reason' => 'Authentication reservation expired.',
        ]);
        $this->assertSame(ExecutionStatus::Failed, $staleSessionQuery->fresh()->status);
        $this->assertSame(ExecutionStatus::Failed, $staleExecution->fresh()->status);
    }
}
