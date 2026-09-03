<?php

namespace Database\Seeders;

use App\Enums\AccessMode;
use App\Enums\AccessTransport;
use App\Enums\AuthProviderType;
use App\Enums\DatabaseDriver;
use App\Enums\DatabaseTlsMode;
use App\Enums\ExecutionStatus;
use App\Enums\NativeProxyConnectionStatus;
use App\Enums\NativeProxyLeaseStatus;
use App\Enums\PreflightStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Models\AuditLog;
use App\Models\AuthProvider;
use App\Models\ConnectionGroup;
use App\Models\DatabaseConnection;
use App\Models\NativeProxyConnection;
use App\Models\NativeProxyLease;
use App\Models\NotificationSubscription;
use App\Models\QueryExecution;
use App\Models\QueryRequest;
use App\Models\QueryRequestStatement;
use App\Models\QueryReview;
use App\Models\QuerySession;
use App\Models\QuerySessionQuery;
use App\Models\Role;
use App\Models\RoleConnectionGroupPolicy;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use App\Notifications\OperationalNotification;
use Carbon\CarbonInterface;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use LogicException;

class DocumentationSeeder extends Seeder
{
    use WithoutModelEvents;

    public const string InvitationToken = 'crucible-documentation-invitation';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Documentation fixtures may only be seeded locally or during tests.');
        }

        if (! User::query()->where('email', 'admin@example.com')->exists()) {
            $this->call(DatabaseSeeder::class);
        }

        Model::unguarded(function (): void {
            $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
            $requesterRole = Role::query()->where('slug', 'developer')->firstOrFail();
            $reviewerRole = Role::query()->updateOrCreate(
                ['slug' => 'database-reviewer'],
                [
                    'name' => 'Database Reviewer',
                    'description' => 'Reviews governed database work without workspace administration access.',
                    'is_admin' => false,
                ],
            );

            $admin = $this->documentationUser(
                'admin@example.com',
                'Alex Morgan',
                'Alex',
                'Morgan',
                $adminRole,
            );
            $requester = $this->documentationUser(
                'developer@example.com',
                'Maya Chen',
                'Maya',
                'Chen',
                $requesterRole,
            );
            $reviewer = $this->documentationUser(
                'reviewer@example.com',
                'Jordan Lee',
                'Jordan',
                'Lee',
                $reviewerRole,
            );
            $this->documentationTwoFactorUser($requesterRole);
            $this->documentationInvitedUser($admin);

            $postgres = DatabaseConnection::query()
                ->whereIn('name', ['Local Target PostgreSQL', 'Production Orders'])
                ->firstOrFail();
            $postgres->update([
                'name' => 'Production Orders',
                'created_by_id' => $admin->id,
            ]);

            $mysql = DatabaseConnection::query()
                ->whereIn('name', ['Local Target MySQL', 'Staging Analytics'])
                ->firstOrFail();
            $mysql->update([
                'name' => 'Staging Analytics',
                'created_by_id' => $admin->id,
            ]);

            $connectionGroup = ConnectionGroup::query()->updateOrCreate(
                ['name' => 'Customer-facing services'],
                ['description' => 'Explicit membership for governed customer-facing database targets.'],
            );
            $connectionGroup->databaseConnections()->sync([$postgres->id, $mysql->id]);

            RoleDatabasePermission::query()
                ->whereIn('role_id', [$requesterRole->id, $reviewerRole->id])
                ->whereIn('database_connection_id', [$postgres->id, $mysql->id])
                ->delete();

            RoleConnectionGroupPolicy::query()->updateOrCreate(
                [
                    'role_id' => $requesterRole->id,
                    'connection_group_id' => $connectionGroup->id,
                ],
                [
                    'access_mode' => AccessMode::Write,
                    'query_access_mode' => AccessMode::Read,
                    'native_proxy_access_mode' => AccessMode::Read,
                    'can_review' => false,
                    'requires_approval' => true,
                    'read_requires_approval' => true,
                    'write_requires_approval' => true,
                    'max_write_session_minutes' => 60,
                ],
            );
            RoleDatabasePermission::query()->updateOrCreate(
                [
                    'role_id' => $requesterRole->id,
                    'database_connection_id' => $postgres->id,
                ],
                [
                    'access_mode' => AccessMode::Write,
                    'query_access_mode' => AccessMode::Read,
                    'native_proxy_access_mode' => AccessMode::Read,
                    'can_review' => false,
                    'requires_approval' => true,
                    'read_requires_approval' => true,
                    'write_requires_approval' => true,
                    'max_write_session_minutes' => 60,
                ],
            );
            RoleConnectionGroupPolicy::query()->updateOrCreate(
                [
                    'role_id' => $reviewerRole->id,
                    'connection_group_id' => $connectionGroup->id,
                ],
                [
                    'access_mode' => AccessMode::Read,
                    'query_access_mode' => AccessMode::Read,
                    'native_proxy_access_mode' => AccessMode::Read,
                    'can_review' => true,
                    'requires_approval' => false,
                    'read_requires_approval' => false,
                    'write_requires_approval' => false,
                    'max_write_session_minutes' => null,
                ],
            );

            $pendingDeployment = $this->deploymentRequest(
                requester: $requester,
                connection: $postgres,
                title: 'DEP-2042: Correct account status',
                description: 'Correct one customer account after the support ticket was verified.',
                sql: "UPDATE accounts SET status = 'active' WHERE id = 1042;",
                status: QueryRequestStatus::PendingReview,
            );

            $scheduledDeployment = $this->deploymentRequest(
                requester: $requester,
                connection: $postgres,
                title: 'DEP-2041: Mark imported orders reviewed',
                description: 'Mark the verified legacy import as reviewed during the next maintenance window.',
                sql: "UPDATE orders SET reviewed_at = CURRENT_TIMESTAMP WHERE imported_batch = 'legacy-2026-08';",
                status: QueryRequestStatus::Scheduled,
                reviewer: $reviewer,
                scheduledAt: now()->addDay()->startOfHour(),
            );

            $completedRead = $this->deploymentRequest(
                requester: $requester,
                connection: $mysql,
                title: 'Verify reporting replica readiness',
                description: 'Confirm the reporting target is ready before the weekly export.',
                sql: 'SELECT status, checked_at FROM replica_health LIMIT 10;',
                status: QueryRequestStatus::Completed,
                reviewer: $reviewer,
            );
            $completedStatement = $completedRead->statements()->firstOrFail();
            QueryExecution::query()->updateOrCreate(
                [
                    'query_request_id' => $completedRead->id,
                    'query_request_statement_id' => $completedStatement->id,
                ],
                [
                    'database_connection_id' => $mysql->id,
                    'executed_by_id' => $requester->id,
                    'sql' => $completedStatement->sql,
                    'query_type' => QueryType::Read,
                    'status' => ExecutionStatus::Succeeded,
                    'started_at' => now()->subHours(2),
                    'finished_at' => now()->subHours(2)->addMilliseconds(84),
                    'duration_ms' => 84,
                    'row_count' => 1,
                    'result_truncated' => false,
                    'sample_rows' => [['status' => 'ready', 'checked_at' => now()->subHours(2)->toIso8601String()]],
                    'error_message' => null,
                ],
            );

            $blockedDraft = $this->deploymentRequest(
                requester: $requester,
                connection: $postgres,
                title: 'Draft: review unsupported maintenance SQL',
                description: 'Saved for administrator review because the statement is outside the governed SQL families.',
                sql: 'VACUUM ANALYZE orders;',
                status: QueryRequestStatus::Draft,
                preflightStatus: PreflightStatus::Blocked,
                preflightMessage: 'This SQL statement is not supported by the current workspace policy.',
            );

            $queryAccess = QueryRequest::query()->updateOrCreate(
                ['title' => 'Investigate checkout latency'],
                [
                    'requester_id' => $requester->id,
                    'database_connection_id' => $postgres->id,
                    'description' => 'Inspect recent order timing data across the approved customer-facing targets.',
                    'sql' => '',
                    'query_type' => QueryType::Read,
                    'request_kind' => QueryRequestKind::QueryAccess,
                    'requested_access_mode' => AccessMode::Read,
                    'status' => QueryRequestStatus::PendingReview,
                    'requires_approval' => true,
                    'preflight_status' => PreflightStatus::NotRun,
                    'preflight_report' => null,
                    'preflight_checked_at' => null,
                    'scheduled_at' => null,
                    'access_duration_minutes' => 60,
                    'approved_by_id' => null,
                    'approved_at' => null,
                    'dispatched_at' => null,
                    'completed_at' => null,
                    'last_error' => null,
                ],
            );
            $queryAccess->accessConnections()->sync([$postgres->id, $mysql->id]);

            $activeQueryAccess = QueryRequest::query()->updateOrCreate(
                ['title' => 'Approved Query Access: inspect checkout timing'],
                [
                    'requester_id' => $requester->id,
                    'database_connection_id' => $postgres->id,
                    'approved_by_id' => $reviewer->id,
                    'description' => 'Inspect synthetic checkout timing records during the approved read-only window.',
                    'sql' => '',
                    'query_type' => QueryType::Read,
                    'request_kind' => QueryRequestKind::QueryAccess,
                    'requested_access_mode' => AccessMode::Read,
                    'status' => QueryRequestStatus::Approved,
                    'requires_approval' => true,
                    'preflight_status' => PreflightStatus::NotRun,
                    'preflight_report' => null,
                    'preflight_checked_at' => null,
                    'scheduled_at' => null,
                    'access_duration_minutes' => 60,
                    'approved_at' => now()->subMinutes(10),
                    'dispatched_at' => null,
                    'completed_at' => null,
                    'last_error' => null,
                ],
            );
            $activeQueryAccess->accessConnections()->sync([$postgres->id, $mysql->id]);
            QueryReview::query()->updateOrCreate(
                [
                    'query_request_id' => $activeQueryAccess->id,
                    'reviewer_id' => $reviewer->id,
                ],
                [
                    'decision' => 'approved',
                    'comment' => 'Read-only access scope and duration are appropriate for this investigation.',
                ],
            );
            $querySession = QuerySession::query()->updateOrCreate(
                [
                    'query_request_id' => $activeQueryAccess->id,
                    'user_id' => $requester->id,
                ],
                [
                    'database_connection_id' => $postgres->id,
                    'started_at' => now()->subMinutes(10),
                    'expires_at' => now()->addMinutes(50),
                    'ended_at' => null,
                ],
            );
            $querySession->databaseConnections()->sync([$postgres->id, $mysql->id]);
            QuerySessionQuery::query()->updateOrCreate(
                [
                    'query_session_id' => $querySession->id,
                    'sql' => 'SELECT reference, duration_ms FROM checkout_timings ORDER BY recorded_at DESC LIMIT 3;',
                ],
                [
                    'database_connection_id' => $postgres->id,
                    'user_id' => $requester->id,
                    'query_type' => QueryType::Read,
                    'status' => ExecutionStatus::Succeeded,
                    'started_at' => now()->subMinutes(4),
                    'finished_at' => now()->subMinutes(4)->addMilliseconds(42),
                    'duration_ms' => 42,
                    'row_count' => 3,
                    'result_truncated' => false,
                    'sample_rows' => [
                        ['reference' => 'ORD-2042', 'duration_ms' => 184],
                        ['reference' => 'ORD-2041', 'duration_ms' => 211],
                        ['reference' => 'ORD-2040', 'duration_ms' => 176],
                    ],
                    'error_message' => null,
                ],
            );

            $nativeQueryAccess = QueryRequest::query()->updateOrCreate(
                ['title' => 'Native Client: investigate checkout timing'],
                [
                    'requester_id' => $requester->id,
                    'database_connection_id' => $postgres->id,
                    'approved_by_id' => $reviewer->id,
                    'description' => 'Inspect synthetic checkout timing through a local-only desktop client tunnel.',
                    'sql' => '',
                    'query_type' => QueryType::Read,
                    'request_kind' => QueryRequestKind::QueryAccess,
                    'access_transport' => AccessTransport::NativeProxy,
                    'requested_access_mode' => AccessMode::Read,
                    'status' => QueryRequestStatus::Approved,
                    'requires_approval' => true,
                    'preflight_status' => PreflightStatus::NotRun,
                    'access_duration_minutes' => 60,
                    'approved_at' => now()->subMinutes(5),
                ],
            );
            $nativeQueryAccess->accessConnections()->sync([$postgres->id]);
            $nativeSession = QuerySession::query()->updateOrCreate(
                ['query_request_id' => $nativeQueryAccess->id, 'user_id' => $requester->id],
                [
                    'database_connection_id' => $postgres->id,
                    'started_at' => now()->subMinutes(5),
                    'expires_at' => now()->addMinutes(55),
                    'ended_at' => null,
                ],
            );
            $nativeLease = NativeProxyLease::query()->updateOrCreate(
                ['query_session_id' => $nativeSession->id],
                [
                    'query_request_id' => $nativeQueryAccess->id,
                    'user_id' => $requester->id,
                    'database_connection_id' => $postgres->id,
                    'protocol' => DatabaseDriver::PostgreSql,
                    'access_mode' => AccessMode::Read,
                    'synthetic_username' => 'docs-native-client',
                    'synthetic_password_hash' => hash('sha256', 'not-a-real-password'),
                    'protocol_auth_secret' => null,
                    'credential_version' => 1,
                    'status' => NativeProxyLeaseStatus::Active,
                    'max_concurrent_connections' => 3,
                    'activated_at' => now()->subMinutes(5),
                    'expires_at' => $nativeSession->expires_at,
                ],
            );
            $nativeConnection = NativeProxyConnection::query()->updateOrCreate(
                ['proxy_connection_id' => 'documentation-native-connection'],
                [
                    'lease_id' => $nativeLease->id,
                    'query_session_id' => $nativeSession->id,
                    'query_request_id' => $nativeQueryAccess->id,
                    'user_id' => $requester->id,
                    'database_connection_id' => $postgres->id,
                    'protocol' => DatabaseDriver::PostgreSql,
                    'proxy_instance_id' => 'documentation-proxy',
                    'client_application' => 'DBeaver',
                    'upstream_tls_mode' => DatabaseTlsMode::VerifyIdentity,
                    'upstream_tls_verified' => true,
                    'status' => NativeProxyConnectionStatus::Active,
                    'connected_at' => now()->subMinutes(3),
                    'authenticated_at' => now()->subMinutes(3),
                    'last_activity_at' => now()->subMinute(),
                    'statement_count' => 1,
                ],
            );
            QuerySessionQuery::query()->updateOrCreate(
                ['query_session_id' => $nativeSession->id, 'native_proxy_connection_id' => $nativeConnection->id],
                [
                    'database_connection_id' => $postgres->id,
                    'user_id' => $requester->id,
                    'sql' => '',
                    'native_protocol_command' => 'query',
                    'native_sql_fingerprint' => 'f6d7b3ca6bbf8469',
                    'native_parameter_count' => 0,
                    'query_type' => QueryType::Read,
                    'status' => ExecutionStatus::Succeeded,
                    'started_at' => now()->subMinute(),
                    'finished_at' => now()->subMinute()->addMilliseconds(28),
                    'duration_ms' => 28,
                    'row_count' => 3,
                    'result_truncated' => false,
                    'sample_rows' => null,
                ],
            );

            NotificationSubscription::query()->updateOrCreate([
                'user_id' => $requester->id,
                'subscribable_type' => QueryRequest::class,
                'subscribable_id' => $scheduledDeployment->id,
            ]);
            NotificationSubscription::query()->updateOrCreate([
                'user_id' => $requester->id,
                'subscribable_type' => DatabaseConnection::class,
                'subscribable_id' => $postgres->id,
            ]);

            $requester->notifications()
                ->whereIn('data->event', [
                    'documentation.request_approved',
                    'documentation.session_active',
                ])
                ->delete();
            $requester->notifyNow(new OperationalNotification([
                'event' => 'documentation.request_approved',
                'severity' => 'success',
                'title' => 'Request approved',
                'message' => 'DEP-2041 is approved for the scheduled maintenance window.',
                'action_label' => 'Open request',
                'url' => route('query-requests.show', $scheduledDeployment),
                'request_id' => $scheduledDeployment->id,
            ], true, false));
            $requester->notifyNow(new OperationalNotification([
                'event' => 'documentation.session_active',
                'severity' => 'info',
                'title' => 'Query access session active',
                'message' => 'Your read-only checkout investigation session is ready.',
                'action_label' => 'Open session',
                'url' => route('query-sessions.show', $querySession),
                'request_id' => $activeQueryAccess->id,
                'session_id' => $querySession->id,
            ], true, false));

            AuthProvider::query()->updateOrCreate(
                ['provider' => AuthProviderType::Google],
                [
                    'name' => 'Engineering Google SSO',
                    'client_id' => 'documentation-client-id',
                    'client_secret' => 'documentation-client-secret',
                    'scopes' => AuthProviderType::Google->defaultScopes(),
                    'allowed_domains' => ['example.com'],
                    'is_enabled' => false,
                ],
            );

            $auditEntries = [
                [$pendingDeployment, $requester, 'query_request.created'],
                [$scheduledDeployment, $reviewer, 'query_request.approved'],
                [$completedRead, $requester, 'query_request.execution_completed'],
                [$blockedDraft, $requester, 'query_request.draft_saved'],
                [$queryAccess, $requester, 'query_access.requested'],
                [$activeQueryAccess, $reviewer, 'query_session.started'],
                [$nativeQueryAccess, $requester, 'native_proxy.lease_created'],
            ];

            foreach ($auditEntries as [$queryRequest, $actor, $action]) {
                AuditLog::query()->updateOrCreate(
                    [
                        'action' => $action,
                        'auditable_type' => QueryRequest::class,
                        'auditable_id' => $queryRequest->id,
                    ],
                    [
                        'actor_id' => $actor->id,
                        'ip_address' => '192.0.2.10',
                        'user_agent' => 'Crucible documentation fixture',
                        'metadata' => ['source' => 'documentation'],
                    ],
                );
            }
        });
    }

    private function documentationUser(
        string $email,
        string $name,
        string $firstName,
        string $lastName,
        Role $role,
    ): User {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'role_id' => $role->id,
                'name' => $name,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'timezone' => 'UTC',
                'password' => 'password',
                'email_verified_at' => now(),
                'invitation_accepted_at' => now(),
                'disabled_at' => null,
            ],
        );
        $user->roles()->sync([$role->id => ['priority' => 100]]);

        return $user;
    }

    private function documentationInvitedUser(User $admin): void
    {
        User::query()->updateOrCreate(
            ['email' => 'invited@example.com'],
            [
                'role_id' => null,
                'name' => 'Taylor Rivera',
                'first_name' => 'Taylor',
                'last_name' => 'Rivera',
                'timezone' => 'UTC',
                'password' => Str::random(64),
                'email_verified_at' => null,
                'invited_by_id' => $admin->id,
                'invited_at' => now()->subDay(),
                'invitation_accepted_at' => null,
                'invitation_token_hash' => hash('sha256', self::InvitationToken),
                'disabled_at' => null,
            ],
        );
    }

    private function documentationTwoFactorUser(Role $role): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'twofactor@example.com'],
            [
                'role_id' => $role->id,
                'name' => 'Casey Patel',
                'first_name' => 'Casey',
                'last_name' => 'Patel',
                'timezone' => 'UTC',
                'password' => 'password',
                'email_verified_at' => now(),
                'invitation_accepted_at' => now(),
                'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
                'two_factor_recovery_codes' => encrypt(json_encode(['documentation-recovery-code'], JSON_THROW_ON_ERROR)),
                'two_factor_confirmed_at' => now(),
                'disabled_at' => null,
            ],
        );
        $user->roles()->sync([$role->id => ['priority' => 100]]);
    }

    private function deploymentRequest(
        User $requester,
        DatabaseConnection $connection,
        string $title,
        string $description,
        string $sql,
        QueryRequestStatus $status,
        ?User $reviewer = null,
        ?CarbonInterface $scheduledAt = null,
        PreflightStatus $preflightStatus = PreflightStatus::Passed,
        ?string $preflightMessage = null,
    ): QueryRequest {
        $queryType = Str::of($sql)->trim()->lower()->startsWith('select') ? QueryType::Read : QueryType::Write;
        $messages = $preflightMessage === null
            ? []
            : [[
                'level' => 'blocked',
                'code' => 'invalid_sql',
                'message' => $preflightMessage,
            ]];
        $checkedAt = now()->subMinutes(5);
        $approvedAt = $reviewer instanceof User ? now()->subHours(3) : null;
        $completedAt = $status === QueryRequestStatus::Completed ? now()->subHours(2) : null;

        $queryRequest = QueryRequest::query()->updateOrCreate(
            ['title' => $title],
            [
                'requester_id' => $requester->id,
                'database_connection_id' => $connection->id,
                'approved_by_id' => $reviewer?->id,
                'description' => $description,
                'sql' => $sql,
                'query_type' => $queryType,
                'request_kind' => QueryRequestKind::SingleExecution,
                'requested_access_mode' => null,
                'status' => $status,
                'requires_approval' => true,
                'preflight_status' => $preflightStatus,
                'preflight_report' => [
                    'checked_at' => $checkedAt->toIso8601String(),
                    'summary' => [
                        'blocker_count' => count($messages),
                        'warning_count' => 0,
                    ],
                    'statements' => [[
                        'position' => 1,
                        'connection_id' => $connection->id,
                        'connection_name' => $connection->name,
                        'query_type' => $queryType->value,
                        'status' => $preflightStatus === PreflightStatus::Blocked ? 'blocked' : 'passed',
                        'messages' => $messages,
                    ]],
                ],
                'preflight_checked_at' => $checkedAt,
                'scheduled_at' => $scheduledAt,
                'access_duration_minutes' => null,
                'approved_at' => $approvedAt,
                'dispatched_at' => $completedAt?->copy()->subMinute(),
                'completed_at' => $completedAt,
                'result_summary' => $completedAt === null ? null : ['statement_count' => 1, 'succeeded_count' => 1],
                'last_error' => null,
            ],
        );

        $statement = QueryRequestStatement::query()->updateOrCreate(
            [
                'query_request_id' => $queryRequest->id,
                'position' => 1,
            ],
            [
                'database_connection_id' => $connection->id,
                'sql' => $sql,
                'query_type' => $queryType,
            ],
        );

        if ($reviewer instanceof User) {
            QueryReview::query()->updateOrCreate(
                [
                    'query_request_id' => $queryRequest->id,
                    'reviewer_id' => $reviewer->id,
                ],
                [
                    'decision' => 'approved',
                    'comment' => 'Scope and preflight checks reviewed against the maintenance plan.',
                ],
            );
        } else {
            QueryReview::query()->where('query_request_id', $queryRequest->id)->delete();
        }

        if ($status !== QueryRequestStatus::Completed) {
            QueryExecution::query()
                ->where('query_request_id', $queryRequest->id)
                ->where('query_request_statement_id', $statement->id)
                ->delete();
        }

        return $queryRequest;
    }
}
