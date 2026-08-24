<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Enums\PreflightStatus;
use App\Enums\QueryRequestStatus;
use App\Models\ConnectionGroup;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\Role;
use App\Models\RoleConnectionGroupPolicy;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use Database\Seeders\DocumentationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_documentation_seeder_creates_repeatable_role_and_workflow_fixtures(): void
    {
        $this->seed(DocumentationSeeder::class);
        $this->seed(DocumentationSeeder::class);

        $requester = User::query()->where('email', 'developer@example.com')->firstOrFail();
        $reviewer = User::query()->where('email', 'reviewer@example.com')->firstOrFail();
        $reviewerRole = Role::query()->where('slug', 'database-reviewer')->firstOrFail();
        $connectionGroup = ConnectionGroup::query()->where('name', 'Customer-facing services')->firstOrFail();
        $reviewerPolicy = RoleConnectionGroupPolicy::query()
            ->whereBelongsTo($reviewerRole)
            ->whereBelongsTo($connectionGroup)
            ->firstOrFail();
        $requesterException = RoleDatabasePermission::query()
            ->where('role_id', $requester->roles()->firstOrFail()->id)
            ->whereHas('databaseConnection', fn ($query) => $query->where('name', 'Production Orders'))
            ->firstOrFail();
        $pendingDeployment = QueryRequest::query()
            ->where('title', 'DEP-2042: Correct account status')
            ->firstOrFail();
        $blockedDraft = QueryRequest::query()
            ->where('title', 'Draft: review unsupported maintenance SQL')
            ->firstOrFail();
        $activeSession = QuerySession::query()->firstOrFail();
        $invitedUser = User::query()->where('email', 'invited@example.com')->firstOrFail();

        $this->assertSame('Maya Chen', $requester->name);
        $this->assertSame('Jordan Lee', $reviewer->name);
        $this->assertSame(AccessMode::Read, $reviewerPolicy->access_mode);
        $this->assertTrue($reviewerPolicy->can_review);
        $this->assertSame(AccessMode::Write, $requesterException->access_mode);
        $this->assertSame(AccessMode::Read, $requesterException->query_access_mode);
        $this->assertSame(QueryRequestStatus::PendingReview, $pendingDeployment->status);
        $this->assertCount(1, $pendingDeployment->statements);
        $this->assertSame(PreflightStatus::Blocked, $blockedDraft->preflight_status);
        $this->assertTrue($activeSession->isActive());
        $this->assertCount(1, $activeSession->queries);
        $this->assertNull($invitedUser->invitation_accepted_at);
        $this->assertSame(
            hash('sha256', DocumentationSeeder::InvitationToken),
            $invitedUser->invitation_token_hash,
        );
        $this->assertDatabaseCount('query_requests', 6);
        $this->assertDatabaseCount('query_reviews', 3);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('notification_subscriptions', 2);

        $this->actingAs($requester)->get(route('dashboard'))->assertOk();
        $this->actingAs($reviewer)->get(route('dashboard'))->assertOk();
    }
}
