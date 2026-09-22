<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Enums\QueryRequestStatus;
use App\Models\NativeProxyConnection;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\Role;
use App\Models\SqlPolicyCandidate;
use App\Models\SqlPolicyCandidateOccurrence;
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

    public function test_authenticated_pages_share_native_proxy_header_status(): void
    {
        config()->set('native_proxy.enabled', true);
        config()->set('native_proxy.health_url', 'http://native-proxy:8081/readyz');
        config()->set('native_proxy.expected_version', '0.2.10');

        Http::fake([
            'http://native-proxy:8081/readyz' => Http::response([
                'proxy_id' => 'proxy-a',
                'version' => '0.2.10',
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

        $this->actingAs($admin)
            ->get(route('connections.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('connections/index')
                ->where('native_proxy_status.health.status', 'healthy')
                ->where('native_proxy_status.health.version', '0.2.10')
                ->where('native_proxy_status.connections', 1)
                ->where('native_proxy_status.instances', 1)
                ->missing('native_proxy_status.health.password')
                ->missing('native_proxy_status.health.parameters')
                ->missing('native_proxy_status.health.rows'));
    }

    public function test_shared_native_proxy_header_counts_only_sessions_visible_to_the_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        foreach ([$user, $otherUser] as $sessionUser) {
            $request = QueryRequest::factory()->queryAccess()->create([
                'requester_id' => $sessionUser->id,
            ]);
            $session = QuerySession::factory()->create([
                'query_request_id' => $request->id,
                'database_connection_id' => $request->database_connection_id,
                'user_id' => $sessionUser->id,
            ]);
            NativeProxyConnection::factory()->active()->create([
                'query_session_id' => $session->id,
                'query_request_id' => $request->id,
                'database_connection_id' => $request->database_connection_id,
                'proxy_instance_id' => "proxy-{$sessionUser->id}",
            ]);
        }

        $this->actingAs($user)
            ->get(route('connections.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('native_proxy_status.connections', 1)
                ->where('native_proxy_status.instances', 1));
    }

    public function test_dashboard_returns_a_prioritized_operational_queue_visible_to_an_admin(): void
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
        $candidate = SqlPolicyCandidate::factory()->create();
        SqlPolicyCandidateOccurrence::factory()->create([
            'sql_policy_candidate_id' => $candidate->id,
            'query_request_id' => $pendingReview->id,
            'query_request_statement_id' => null,
            'database_connection_id' => $pendingReview->database_connection_id,
            'review_requested_by_id' => $requester->id,
            'review_requested_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('summary.pending_reviews', 1)
                ->where('summary.policy_reviews', 1)
                ->where('summary.scheduled', 1)
                ->where('summary.failed', 1)
                ->where('summary.active_sessions', 1)
                ->has('operational_queue', 5)
                ->where('operational_queue.0.id', $failedRequest->id)
                ->where('operational_queue.0.type', 'failed_execution')
                ->where('operational_queue.0.detail', 'Permission denied.')
                ->where('operational_queue.1.id', $pendingReview->id)
                ->where('operational_queue.1.type', 'pending_review')
                ->where('operational_queue.1.requested_access_mode', AccessMode::Write->value)
                ->where('operational_queue.2.id', $candidate->id)
                ->where('operational_queue.2.type', 'policy_review')
                ->where('operational_queue.3.id', $session->id)
                ->where('operational_queue.3.type', 'active_session')
                ->where('operational_queue.4.id', $scheduledRequest->id)
                ->where('operational_queue.4.type', 'scheduled_execution')
                ->missing('pending_reviews')
                ->missing('scheduled_requests')
                ->missing('failed_requests')
                ->missing('expiring_sessions')
                ->missing('policy_review_candidates')
                ->where('policy_review_summary.pending_count', 1));
    }

    public function test_dashboard_surfaces_cached_native_proxy_health_without_secrets_or_statement_data(): void
    {
        config()->set('native_proxy.enabled', true);
        config()->set('native_proxy.health_url', 'http://native-proxy:8081/readyz');
        config()->set('native_proxy.expected_version', '0.2.10');

        Http::fake([
            'http://native-proxy:8081/readyz' => Http::response([
                'proxy_id' => 'proxy-a',
                'version' => '0.2.10',
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
                ->where('native_proxy_health.version', '0.2.10')
                ->missing('native_proxy_health.password')
                ->missing('native_proxy_health.parameters')
                ->missing('native_proxy_health.rows')
                ->reloadOnly(['summary', 'operational_queue', 'native_proxy_health', 'native_proxy_status'], fn (Assert $reload) => $reload
                    ->where('summary.native_proxy_connections', 1)
                    ->has('operational_queue')
                    ->where('native_proxy_health.status', 'healthy')
                    ->where('native_proxy_status.health.status', 'healthy')
                    ->where('native_proxy_status.connections', 1)
                    ->where('native_proxy_status.instances', 1)));
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
