<?php

namespace App\Services;

use App\Enums\PreflightStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\SqlPolicyCandidateResolution;
use App\Enums\SqlPolicyRuleEffect;
use App\Enums\SqlPolicyRuleMatchType;
use App\Enums\SqlPolicyRuleScope;
use App\Models\ConnectionGroup;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\SqlPolicyCandidate;
use App\Models\SqlPolicyRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SqlPolicyManager
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DeploymentPreflight $deploymentPreflight,
    ) {}

    public function resolveCandidate(
        SqlPolicyCandidate $candidate,
        User $actor,
        string $action,
        SqlPolicyRuleMatchType $matchType,
        SqlPolicyRuleScope $scope,
        ?int $scopeId,
    ): ?SqlPolicyRule {
        if ($action === 'dismiss') {
            $candidate->forceFill([
                'resolution' => SqlPolicyCandidateResolution::Dismissed,
                'resolved_by_id' => $actor->id,
                'resolved_rule_id' => null,
            ])->save();

            $this->auditLogger->log('sql_policy_candidate.dismissed', $actor, $candidate);

            return null;
        }

        $effect = $action === 'deny' ? SqlPolicyRuleEffect::Deny : SqlPolicyRuleEffect::Allow;

        if ($effect === SqlPolicyRuleEffect::Deny) {
            $matchType = SqlPolicyRuleMatchType::Exact;
        }

        if ($matchType === SqlPolicyRuleMatchType::Shape && $candidate->shape_signature === null) {
            throw ValidationException::withMessages([
                'match_type' => 'A reusable statement shape is not available for this candidate.',
            ]);
        }

        $this->ensureScopeCoversCandidate($candidate, $scope, $scopeId);
        $scopeKey = $scope->key($scopeId);
        $matchValue = $matchType === SqlPolicyRuleMatchType::Exact
            ? $candidate->exact_fingerprint
            : hash('sha256', (string) $candidate->shape_signature);

        $rule = DB::transaction(function () use ($actor, $candidate, $effect, $matchType, $scope, $scopeId, $scopeKey, $matchValue): SqlPolicyRule {
            $rule = SqlPolicyRule::query()->updateOrCreate(
                [
                    'database_driver' => $candidate->database_driver->value,
                    'match_type' => $matchType->value,
                    'match_value' => $matchValue,
                    'scope_key' => $scopeKey,
                ],
                [
                    'effect' => $effect,
                    'canonical_sql' => $matchType === SqlPolicyRuleMatchType::Exact ? $candidate->canonical_sql : null,
                    'shape_signature' => $matchType === SqlPolicyRuleMatchType::Shape ? $candidate->shape_signature : null,
                    'shape_label' => $matchType === SqlPolicyRuleMatchType::Shape ? $candidate->shape_label : null,
                    'scope_type' => $scope,
                    'scope_id' => $scope === SqlPolicyRuleScope::Workspace ? null : $scopeId,
                    'created_by_id' => $actor->id,
                    'source_candidate_id' => $candidate->id,
                    'is_enabled' => true,
                ],
            );

            $resolution = match (true) {
                $effect === SqlPolicyRuleEffect::Deny => SqlPolicyCandidateResolution::Denied,
                $matchType === SqlPolicyRuleMatchType::Shape => SqlPolicyCandidateResolution::AllowedShape,
                default => SqlPolicyCandidateResolution::AllowedExact,
            };

            $candidate->forceFill([
                'resolution' => $resolution,
                'resolved_by_id' => $actor->id,
                'resolved_rule_id' => $rule->id,
            ])->save();

            $this->auditLogger->log('sql_policy_candidate.resolved', $actor, $candidate, [
                'resolution' => $resolution->value,
                'rule_id' => $rule->id,
                'effect' => $effect->value,
                'match_type' => $matchType->value,
                'scope' => $scopeKey,
            ]);

            return $rule;
        });

        $this->markActiveDeploymentPreflightStale();
        $this->refreshCandidateRequests($candidate);

        return $rule;
    }

    public function setRuleEnabled(SqlPolicyRule $rule, User $actor, bool $isEnabled): void
    {
        $before = $rule->is_enabled;
        $rule->forceFill(['is_enabled' => $isEnabled])->save();

        $this->auditLogger->log('sql_policy_rule.updated', $actor, $rule, [
            'before' => ['is_enabled' => $before],
            'after' => ['is_enabled' => $isEnabled],
        ]);

        $this->markActiveDeploymentPreflightStale();
    }

    private function ensureScopeCoversCandidate(SqlPolicyCandidate $candidate, SqlPolicyRuleScope $scope, ?int $scopeId): void
    {
        if ($scope === SqlPolicyRuleScope::Workspace) {
            return;
        }

        $observedConnectionIds = $candidate->occurrences()
            ->whereNotNull('database_connection_id')
            ->pluck('database_connection_id')
            ->unique();
        $coversCandidate = match ($scope) {
            SqlPolicyRuleScope::ConnectionGroup => ConnectionGroup::query()
                ->whereKey($scopeId)
                ->whereHas(
                    'databaseConnections',
                    fn ($query) => $query->whereIn('database_connections.id', $observedConnectionIds),
                    '=',
                    $observedConnectionIds->count(),
                )
                ->exists(),
            SqlPolicyRuleScope::DatabaseConnection => $observedConnectionIds->count() === 1
                && $observedConnectionIds->first() === $scopeId
                && DatabaseConnection::query()->whereKey($scopeId)->exists(),
        };

        if (! $coversCandidate) {
            throw ValidationException::withMessages([
                'scope_type' => 'Choose a policy scope that covers every observed occurrence.',
            ]);
        }
    }

    private function markActiveDeploymentPreflightStale(): void
    {
        QueryRequest::query()
            ->where('request_kind', QueryRequestKind::SingleExecution->value)
            ->whereIn('status', [
                QueryRequestStatus::Draft->value,
                QueryRequestStatus::PendingReview->value,
                QueryRequestStatus::Approved->value,
                QueryRequestStatus::Scheduled->value,
                QueryRequestStatus::Rejected->value,
            ])
            ->update(['preflight_status' => PreflightStatus::Stale->value]);
    }

    private function refreshCandidateRequests(SqlPolicyCandidate $candidate): void
    {
        $requestIds = $candidate->occurrences()->pluck('query_request_id')->unique();

        QueryRequest::query()
            ->whereIn('id', $requestIds)
            ->whereIn('status', [
                QueryRequestStatus::Draft->value,
                QueryRequestStatus::PendingReview->value,
                QueryRequestStatus::Approved->value,
                QueryRequestStatus::Scheduled->value,
                QueryRequestStatus::Rejected->value,
            ])
            ->get()
            ->each(function (QueryRequest $queryRequest): void {
                $report = $this->deploymentPreflight->evaluate($queryRequest);
                $this->deploymentPreflight->persist($queryRequest, $report);
            });
    }
}
