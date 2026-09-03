<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Enums\DatabaseDriver;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Enums\SqlPolicyCandidateResolution;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\QueryRequestStatement;
use App\Models\Role;
use App\Models\SqlPolicyCandidate;
use App\Models\SqlPolicyRule;
use App\Models\User;
use App\Notifications\OperationalNotification;
use App\Services\ApplicationSettings;
use App\Services\DeploymentPreflight;
use App\Services\DeploymentStatementPolicy;
use App\Services\NativeProxy\ProxyStatementPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SqlPolicyCandidateTest extends TestCase
{
    use RefreshDatabase;

    public function test_ddl_preflight_does_not_receive_an_update_or_delete_where_warning(): void
    {
        Notification::fake();
        $this->enableFallback();
        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->postgresql()->create();
        $queryRequest = $this->deploymentRequest($admin, $connection, [
            'CREATE TABLE IF NOT EXISTS request_logs (id BIGSERIAL PRIMARY KEY)',
            'CREATE INDEX IF NOT EXISTS request_logs_id_idx ON request_logs (id)',
            'CREATE INDEX request_logs_created_idx ON request_logs (created_at)',
        ]);

        $report = app(DeploymentPreflight::class)->evaluate($queryRequest);
        $messageCodes = collect($report['statements'])
            ->flatMap(fn (array $statement): array => array_column($statement['messages'], 'code'));

        $this->assertSame(2, $report['summary']['warning_count']);
        $this->assertFalse($messageCodes->contains('unbounded_write'));
        $this->assertSame([], $report['statements'][0]['messages']);
        $this->assertSame('emergency_sql_fallback', $report['statements'][1]['messages'][0]['code']);
        $this->assertSame('emergency_sql_fallback', $report['statements'][2]['messages'][0]['code']);
    }

    public function test_fallback_preflight_collects_and_deduplicates_policy_candidates(): void
    {
        Notification::fake();
        $this->enableFallback();
        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->postgresql()->create();
        $queryRequest = $this->deploymentRequest($admin, $connection, [
            'CREATE INDEX users_email_idx ON users (email)',
        ]);

        $preflight = app(DeploymentPreflight::class);
        $firstReport = $preflight->evaluate($queryRequest);
        $preflight->evaluate($queryRequest);

        $candidate = SqlPolicyCandidate::query()->sole();

        $this->assertSame(1, $candidate->occurrences()->count());
        $this->assertSame('CREATE INDEX', $candidate->shape_label);
        $this->assertSame($candidate->id, $firstReport['statements'][0]['messages'][0]['candidate_id']);
        Notification::assertSentToTimes($admin, OperationalNotification::class, 1);

        $this->actingAs($admin)
            ->get(route('sql-statement-policy.edit', ['candidate' => $candidate->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/admin/sql-policy')
                ->where('selected_candidate_id', $candidate->id)
                ->where('candidates.total', 1)
                ->where('candidates.data.0.shape_available', true));
    }

    public function test_unsupported_preflight_collects_a_candidate_while_remaining_blocked_without_fallback(): void
    {
        Notification::fake();
        $this->disableFallback();
        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->postgresql()->create();
        $queryRequest = $this->deploymentRequest($admin, $connection, [
            'CREATE INDEX users_email_idx ON users (email)',
        ]);

        $preflight = app(DeploymentPreflight::class);
        $firstReport = $preflight->evaluate($queryRequest);
        $preflight->evaluate($queryRequest);

        $candidate = SqlPolicyCandidate::query()->sole();
        $message = $firstReport['statements'][0]['messages'][0];

        $this->assertSame('blocked', $firstReport['status']->value);
        $this->assertSame(1, $firstReport['summary']['blocker_count']);
        $this->assertSame('unsupported_sql_policy', $message['code']);
        $this->assertSame($candidate->id, $message['candidate_id']);
        $this->assertTrue($message['shape_available']);
        $this->assertSame('CREATE INDEX', $candidate->shape_label);
        $this->assertSame(1, $candidate->occurrences()->count());
        Notification::assertSentToTimes($admin, OperationalNotification::class, 1);
    }

    public function test_immutable_safety_block_does_not_create_a_policy_candidate(): void
    {
        Notification::fake();
        $this->disableFallback();
        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->postgresql()->create();
        $queryRequest = $this->deploymentRequest($admin, $connection, [
            'CREATE ROLE deployment_operator',
        ]);

        $report = app(DeploymentPreflight::class)->evaluate($queryRequest);

        $this->assertSame('blocked', $report['status']->value);
        $this->assertSame('invalid_sql', $report['statements'][0]['messages'][0]['code']);
        $this->assertDatabaseCount('sql_policy_candidates', 0);
        Notification::assertNothingSent();
    }

    public function test_admin_can_allow_an_exact_candidate_without_broadening_native_client_sql(): void
    {
        Notification::fake();
        $this->enableFallback();
        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->postgresql()->create();
        $sql = 'CREATE INDEX users_email_idx ON users (email)';
        $queryRequest = $this->deploymentRequest($admin, $connection, [$sql]);
        app(DeploymentPreflight::class)->evaluate($queryRequest);
        $candidate = SqlPolicyCandidate::query()->sole();

        $this->actingAs($admin)
            ->post(route('sql-policy-candidates.resolve', $candidate), [
                'action' => 'allow',
                'match_type' => 'exact',
                'scope_type' => 'workspace',
                'scope_id' => null,
            ])
            ->assertSessionHasNoErrors();

        $this->disableFallback();
        $inspection = app(DeploymentStatementPolicy::class)->inspect($sql, $connection);

        $this->assertSame('custom_exact', $inspection['source']);
        $this->assertSame(QueryType::Write, $inspection['query_type']);
        $this->assertSame(SqlPolicyCandidateResolution::AllowedExact, $candidate->refresh()->resolution);
        $this->assertDatabaseCount('sql_policy_rules', 1);

        try {
            app(DeploymentStatementPolicy::class)->inspect(
                'CREATE INDEX users_name_idx ON users (name)',
                $connection,
            );
            $this->fail('A different statement must not match an exact custom rule.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'This SQL statement is not supported by the governed SQL policy.',
                $exception->errors()['sql'][0],
            );
        }

        $nativeDecision = app(ProxyStatementPolicy::class)->decide(
            DatabaseDriver::PostgreSql,
            AccessMode::Write,
            $connection->database,
            $sql,
        );

        $this->assertFalse($nativeDecision->allowed);
    }

    public function test_admin_can_promote_a_parser_proven_create_index_shape_for_one_driver(): void
    {
        Notification::fake();
        $this->enableFallback();
        $admin = $this->adminUser();
        $postgres = DatabaseConnection::factory()->postgresql()->create();
        $mysql = DatabaseConnection::factory()->mysql()->create();
        $queryRequest = $this->deploymentRequest($admin, $postgres, [
            'CREATE INDEX users_email_idx ON users (email)',
        ]);
        app(DeploymentPreflight::class)->evaluate($queryRequest);
        $candidate = SqlPolicyCandidate::query()->sole();

        $this->actingAs($admin)
            ->post(route('sql-policy-candidates.resolve', $candidate), [
                'action' => 'allow',
                'match_type' => 'shape',
                'scope_type' => 'workspace',
                'scope_id' => null,
            ])
            ->assertSessionHasNoErrors();

        $this->disableFallback();

        $this->assertSame(
            'custom_shape',
            app(DeploymentStatementPolicy::class)->inspect(
                'CREATE INDEX orders_reference_idx ON public.orders (reference)',
                $postgres,
            )['source'],
        );

        $this->expectException(ValidationException::class);
        app(DeploymentStatementPolicy::class)->inspect(
            'CREATE INDEX orders_reference_idx ON orders (reference)',
            $mysql,
        );
    }

    public function test_admin_can_deny_an_exact_candidate_and_toggle_the_rule(): void
    {
        Notification::fake();
        $this->enableFallback();
        $admin = $this->adminUser();
        $connection = DatabaseConnection::factory()->postgresql()->create();
        $otherConnection = DatabaseConnection::factory()->postgresql()->create();
        $sql = 'CREATE INDEX users_email_idx ON users (email)';
        $queryRequest = $this->deploymentRequest($admin, $connection, [$sql]);
        app(DeploymentPreflight::class)->evaluate($queryRequest);
        $candidate = SqlPolicyCandidate::query()->sole();

        $this->actingAs($admin)
            ->post(route('sql-policy-candidates.resolve', $candidate), [
                'action' => 'deny',
                'match_type' => 'shape',
                'scope_type' => 'database_connection',
                'scope_id' => $connection->id,
            ])
            ->assertSessionHasNoErrors();

        $rule = SqlPolicyRule::query()->sole();
        $this->assertSame('exact', $rule->match_type->value);

        try {
            app(DeploymentStatementPolicy::class)->inspect($sql, $connection);
            $this->fail('A matching deny rule must block the statement.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'This SQL statement is blocked by a custom deployment policy.',
                $exception->errors()['sql'][0],
            );
        }

        $this->assertSame(
            'emergency_fallback',
            app(DeploymentStatementPolicy::class)->inspect($sql, $otherConnection)['source'],
        );

        $this->actingAs($admin)
            ->patch(route('sql-policy-rules.update', $rule), ['is_enabled' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($rule->refresh()->is_enabled);
        $this->assertSame(
            'emergency_fallback',
            app(DeploymentStatementPolicy::class)->inspect($sql, $connection)['source'],
        );
    }

    public function test_non_admin_cannot_resolve_a_policy_candidate(): void
    {
        $candidate = SqlPolicyCandidate::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('sql-policy-candidates.resolve', $candidate), [
                'action' => 'allow',
                'match_type' => 'exact',
                'scope_type' => 'workspace',
            ])
            ->assertForbidden();

        $this->assertNull($candidate->refresh()->resolution);
    }

    private function enableFallback(): void
    {
        app(ApplicationSettings::class)->put([
            ApplicationSettings::SqlEmergencyFallbackEnabled => true,
        ]);
    }

    private function disableFallback(): void
    {
        app(ApplicationSettings::class)->put([
            ApplicationSettings::SqlEmergencyFallbackEnabled => false,
        ]);
    }

    private function adminUser(): User
    {
        $role = Role::factory()->admin()->create();

        return User::factory()->withRole($role)->create();
    }

    /**
     * @param  list<string>  $statements
     */
    private function deploymentRequest(
        User $requester,
        DatabaseConnection $connection,
        array $statements,
    ): QueryRequest {
        $queryRequest = QueryRequest::factory()->create([
            'requester_id' => $requester->id,
            'database_connection_id' => $connection->id,
            'request_kind' => QueryRequestKind::SingleExecution,
            'status' => QueryRequestStatus::Draft,
            'query_type' => QueryType::Write,
            'sql' => $statements[0],
        ]);

        foreach ($statements as $index => $sql) {
            QueryRequestStatement::factory()->create([
                'query_request_id' => $queryRequest->id,
                'database_connection_id' => $connection->id,
                'position' => $index + 1,
                'sql' => $sql,
                'query_type' => QueryType::Write,
            ]);
        }

        return $queryRequest;
    }
}
