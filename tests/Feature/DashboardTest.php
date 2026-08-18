<?php

namespace Tests\Feature;

use App\Enums\QueryRequestStatus;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_dashboard_returns_operational_queues_visible_to_an_admin(): void
    {
        $admin = User::factory()
            ->withRole(Role::factory()->admin()->create())
            ->create();
        $requester = User::factory()->create();
        $pendingReview = QueryRequest::factory()->create([
            'requester_id' => $requester->id,
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
                ->where('expiring_sessions.0.id', $session->id));
    }
}
