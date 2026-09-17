<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Enums\QueryRequestStatus;
use App\Models\NativeProxyConnection;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\Role;
use App\Models\User;
use App\Services\NativeProxy\ProxyHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_authenticated_pages_share_the_configured_cli_download_url(): void
    {
        config()->set('native_proxy.cli_download_url', 'https://downloads.example.com/crucible');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('native_proxy_cli_download_url', 'https://downloads.example.com/crucible'));
    }

    public function test_dashboard_returns_operational_queues_visible_to_an_admin(): void
    {
        $admin = User::factory()
            ->withRole(Role::factory()->admin()->create())
            ->create();
        $requester = User::factory()->create();
        $pendingReview = QueryRequest::factory()->queryAccess()->create([
            'requester_id' => $requester->id,
            'requested_access_mode' => AccessMode::Write,
        ]);
        $scheduledRequest = QueryRequest::factory()->scheduled()->create([
            'requester_id' => $requester->id,
        ]);
        $failedRequest = QueryRequest::factory()->create([
            'requester_id' => $requester->id,
            'status' => QueryRequestStatus::Failed,
            'completed_at' => now(),
            'last_error' => 'Permission denied.',
        ]);
        $accessRequest = QueryRequest::factory()->queryAccess()->approved()->create([
            'requester_id' => $requester->id,
            'requested_access_mode' => AccessMode::Write,
        ]);
        $session = QuerySession::factory()->create([
            'query_request_id' => $accessRequest->id,
            'database_connection_id' => $accessRequest->database_connection_id,
            'user_id' => $requester->id,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('summary.pending_reviews', 1)
                ->where('summary.scheduled', 1)
                ->where('summary.failed', 1)
                ->where('summary.active_sessions', 1)
                ->where('pending_reviews.0.id', $pendingReview->id)
                ->where('scheduled_requests.0.id', $scheduledRequest->id)
                ->where('failed_requests.0.id', $failedRequest->id)
                ->where('pending_reviews.0.requested_access_mode', AccessMode::Write->value)
                ->where('expiring_sessions.0.id', $session->id));
    }

    public function test_dashboard_surfaces_cached_native_proxy_health_without_secrets_or_statement_data(): void
    {
        config()->set('native_proxy.enabled', true);
        config()->set('native_proxy.health_url', 'http://native-proxy:8081/readyz');
        config()->set('native_proxy.expected_version', '0.2.5');

        Http::fake([
            'http://native-proxy:8081/readyz' => Http::response([
                'proxy_id' => 'proxy-a',
                'version' => '0.2.5',
            ]),
        ]);

        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();
        $request = QueryRequest::factory()->queryAccess()->create();
        $session = QuerySession::factory()->create([
            'query_request_id' => $request->id,
            'database_connection_id' => $request->database_connection_id,
        ]);
        NativeProxyConnection::factory()->active()->create([
            'query_session_id' => $session->id,
            'query_request_id' => $request->id,
            'database_connection_id' => $request->database_connection_id,
            'proxy_instance_id' => 'proxy-a',
        ]);

        app(ProxyHealth::class)->refresh();
        $this->travel(61)->seconds();

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.native_proxy_connections', 1)
                ->where('summary.native_proxy_instances', 1)
                ->where('native_proxy_health.status', 'healthy')
                ->where('native_proxy_health.version', '0.2.5')
                ->missing('native_proxy_health.password')
                ->missing('native_proxy_health.parameters')
                ->missing('native_proxy_health.rows')
                ->reloadOnly(['summary', 'native_proxy_health'], fn (Assert $reload) => $reload
                    ->where('summary.native_proxy_connections', 1)
                    ->where('native_proxy_health.status', 'healthy')));
    }

    public function test_dashboard_keeps_native_proxy_health_visible_before_the_first_health_check(): void
    {
        config()->set('native_proxy.enabled', true);

        $admin = User::factory()->withRole(Role::factory()->admin()->create())->create();

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('native_proxy_health.status', 'unhealthy')
                ->where('native_proxy_health.message', 'The native proxy health check has not reported yet.'));
    }
}
