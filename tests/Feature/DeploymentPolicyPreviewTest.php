<?php

namespace Tests\Feature;

use App\Enums\QueryRequestStatus;
use App\Enums\SqlPolicyRuleEffect;
use App\Enums\SqlPolicyRuleMatchType;
use App\Enums\SqlPolicyRuleScope;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\Role;
use App\Models\RoleDatabasePermission;
use App\Models\SqlPolicyCandidate;
use App\Models\SqlPolicyRule;
use App\Models\User;
use App\Services\SqlPolicyStatementAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DeploymentPolicyPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowed_candidate_has_a_green_edit_preview_and_can_be_submitted_by_its_developer(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $role = Role::factory()->developer()->create();
        $developer = User::factory()->withRole($role)->create();
        $connection = DatabaseConnection::factory()->postgresql()->create();
        RoleDatabasePermission::factory()->write()->create([
            'role_id' => $role->id,
            'database_connection_id' => $connection->id,
            'write_requires_approval' => true,
        ]);
        $sql = 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_tsm_pending_user_exp ON transaction_state_machines USING btree (id_transaction) WHERE user_exp_status = 0';
        $data = [
            'request_kind' => 'single_execution',
            'title' => 'Index policy review',
            'statements' => [['sql' => $sql, 'database_connection_id' => $connection->id]],
        ];

        $this->actingAs($developer)->post(route('query-requests.store'), ['intent' => 'policy_review', ...$data])
            ->assertSessionHasNoErrors();
        $queryRequest = QueryRequest::query()->sole();
        $candidate = SqlPolicyCandidate::query()->sole();

        $this->actingAs($admin)->post(route('sql-policy-candidates.resolve', $candidate), [
            'action' => 'allow', 'match_type' => 'exact', 'scope_type' => 'database_connection', 'scope_id' => $connection->id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($developer)->get(route('query-requests.edit', $queryRequest))
            ->assertInertia(fn (Assert $page) => $page
                ->component('query-requests/create')
                ->where('policy_previews.0.sql', $sql)
                ->where('policy_previews.0.database_connection_id', $connection->id)
                ->where('policy_previews.0.message', null)
                ->where('policy_previews.0.source', 'custom_exact')
                ->where('policy_previews.0.reviewable', false));

        $this->postJson(route('query-requests.policy-preview'), ['statements' => $data['statements']])
            ->assertOk()->assertJsonPath('statements.0.message', null);
        $this->patch(route('query-requests.update', $queryRequest), ['intent' => 'submit', ...$data])
            ->assertSessionHasNoErrors();
        $this->assertSame(QueryRequestStatus::PendingReview, $queryRequest->refresh()->status);
    }

    public function test_live_preview_rechecks_changed_sql_target_and_disabled_rules_without_side_effects(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $connection = DatabaseConnection::factory()->postgresql()->create();
        $otherConnection = DatabaseConnection::factory()->postgresql()->create();
        $sql = 'CREATE INDEX CONCURRENTLY users_email_idx ON users (email)';
        $rule = $this->allowExact($admin, $connection, $sql);
        $inputs = [
            ['sql' => $sql, 'database_connection_id' => $connection->id],
            ['sql' => 'CREATE INDEX CONCURRENTLY users_name_idx ON users (name)', 'database_connection_id' => $connection->id],
            ['sql' => $sql, 'database_connection_id' => $otherConnection->id],
        ];
        $this->actingAs($admin)->postJson(route('query-requests.policy-preview'), ['statements' => $inputs])
            ->assertOk()
            ->assertJsonPath('statements.0.source', 'custom_exact')
            ->assertJsonPath('statements.0.message', null)
            ->assertJsonPath('statements.1.reviewable', true)
            ->assertJsonPath('statements.2.reviewable', true);

        $rule->update(['is_enabled' => false]);
        $this->postJson(route('query-requests.policy-preview'), ['statements' => [$inputs[0]]])
            ->assertOk()->assertJsonPath('statements.0.source', 'unsupported');
        $this->assertDatabaseCount('query_requests', 0);
        $this->assertDatabaseCount('sql_policy_candidates', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        Notification::assertNothingSent();
    }

    public function test_preview_respects_shape_rules_deny_rules_and_immutable_safety_checks(): void
    {
        $admin = $this->admin();
        $connection = DatabaseConnection::factory()->postgresql()->create();
        $sql = 'CREATE INDEX users_email_idx ON users (email)';
        $shape = app(SqlPolicyStatementAnalyzer::class)->statementShape($connection->driver, $sql);
        SqlPolicyRule::factory()->create([
            'created_by_id' => $admin->id,
            'match_type' => SqlPolicyRuleMatchType::Shape,
            'match_value' => $shape['match_value'],
            'shape_signature' => $shape['signature'],
            'shape_label' => $shape['label'],
        ]);
        $this->actingAs($admin)->postJson(route('query-requests.policy-preview'), ['statements' => [
            ['sql' => 'CREATE INDEX users_name_idx ON users (name)', 'database_connection_id' => $connection->id],
            ['sql' => 'CREATE ROLE unsafe_user', 'database_connection_id' => $connection->id],
        ]])->assertOk()
            ->assertJsonPath('statements.0.source', 'custom_shape')
            ->assertJsonPath('statements.0.message', null)
            ->assertJsonPath('statements.1.reviewable', false)
            ->assertJsonPath('statements.1.source', null);

        $this->allowExact($admin, $connection, $sql)->update(['effect' => SqlPolicyRuleEffect::Deny]);
        $this->postJson(route('query-requests.policy-preview'), ['statements' => [
            ['sql' => $sql, 'database_connection_id' => $connection->id],
        ]])->assertOk()
            ->assertJsonPath('statements.0.message', 'This SQL statement is blocked by a custom deployment policy.')
            ->assertJsonPath('statements.0.reviewable', false);
    }

    public function test_preview_requires_authentication_target_access_and_bounded_valid_input(): void
    {
        $admin = $this->admin();
        $connection = DatabaseConnection::factory()->postgresql()->create();
        $input = ['statements' => [['sql' => 'SELECT 1', 'database_connection_id' => $connection->id]]];
        $this->postJson(route('query-requests.policy-preview'), $input)->assertUnauthorized();
        $developer = User::factory()->withRole(Role::factory()->developer()->create())->create();
        $this->actingAs($developer)->postJson(route('query-requests.policy-preview'), $input)->assertForbidden();
        $this->actingAs($admin)->postJson(route('query-requests.policy-preview'), ['statements' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('statements');
        $input['statements'][0]['sql'] = str_repeat('x', 20001);
        $this->actingAs($admin)->postJson(route('query-requests.policy-preview'), $input)
            ->assertOk();
        $input['statements'][0]['sql'] = str_repeat('x', 1000001);
        $this->postJson(route('query-requests.policy-preview'), $input)
            ->assertUnprocessable()->assertJsonValidationErrors('statements.0.sql');
        $connection->update(['is_active' => false]);
        $input['statements'][0]['sql'] = 'SELECT 1';
        $this->postJson(route('query-requests.policy-preview'), $input)->assertForbidden();
    }

    private function admin(): User
    {
        return User::factory()->withRole(Role::factory()->admin()->create())->create();
    }

    private function allowExact(User $admin, DatabaseConnection $connection, string $sql): SqlPolicyRule
    {
        return SqlPolicyRule::factory()->create([
            'created_by_id' => $admin->id,
            'database_driver' => $connection->driver,
            'canonical_sql' => $sql,
            'match_value' => app(SqlPolicyStatementAnalyzer::class)->exactFingerprint($sql),
            'scope_type' => SqlPolicyRuleScope::DatabaseConnection,
            'scope_id' => $connection->id,
            'scope_key' => SqlPolicyRuleScope::DatabaseConnection->key($connection->id),
        ]);
    }
}
