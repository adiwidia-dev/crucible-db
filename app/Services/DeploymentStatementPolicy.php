<?php

namespace App\Services;

use App\Enums\QueryType;
use App\Enums\SqlPolicyRuleEffect;
use App\Enums\SqlPolicyRuleMatchType;
use App\Enums\SqlPolicyRuleScope;
use App\Models\DatabaseConnection;
use App\Models\SqlPolicyRule;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class DeploymentStatementPolicy
{
    public function __construct(
        private readonly QueryGuard $queryGuard,
        private readonly ApplicationSettings $settings,
        private readonly SqlPolicyStatementAnalyzer $analyzer,
    ) {}

    /**
     * Inspect a deployment statement and enforce the current workspace policy.
     *
     * @return array{sql:string,query_type:QueryType,source:'built_in'|'custom_exact'|'custom_shape'|'emergency_fallback',rule:SqlPolicyRule|null,shape:array{signature:string,label:string,match_value:string}|null}
     *
     * @throws ValidationException
     */
    public function inspect(string $sql, DatabaseConnection $connection): array
    {
        $assessment = $this->assess($sql, $connection);

        if ($assessment['source'] === 'unsupported') {
            throw ValidationException::withMessages([
                'sql' => 'This SQL statement is not supported by the governed SQL policy.',
            ]);
        }

        return $assessment;
    }

    /**
     * Assess a deployment statement without treating policy discovery as authorization.
     *
     * Structurally safe unsupported statements are returned to preflight so they can be
     * collected for administrator review while remaining blocked from submission.
     *
     * @return array{sql:string,query_type:QueryType,source:'built_in'|'custom_exact'|'custom_shape'|'emergency_fallback'|'unsupported',rule:SqlPolicyRule|null,shape:array{signature:string,label:string,match_value:string}|null}
     *
     * @throws ValidationException
     */
    public function assess(string $sql, DatabaseConnection $connection): array
    {
        $statement = $this->queryGuard->validateStructure($sql);
        $statementFamily = $this->queryGuard->statementFamily($statement);

        if ($statementFamily !== null) {
            foreach ($this->queryGuard->requiredStatementFamilies($statement) as $requiredStatementFamily) {
                if (! $this->settings->allowsSqlStatementFamily($requiredStatementFamily)) {
                    throw ValidationException::withMessages([
                        'sql' => "{$requiredStatementFamily->label()} statements are disabled by the workspace administrator.",
                    ]);
                }
            }

            return [
                'sql' => $statement,
                'query_type' => $statementFamily->queryType(),
                'source' => 'built_in',
                'rule' => null,
                'shape' => null,
            ];
        }

        $shape = $this->analyzer->statementShape($connection->driver, $statement);
        $rule = $this->matchingRule($connection, $statement, $shape);

        if ($rule?->effect === SqlPolicyRuleEffect::Deny) {
            throw ValidationException::withMessages([
                'sql' => 'This SQL statement is blocked by a custom deployment policy.',
            ]);
        }

        if ($rule instanceof SqlPolicyRule) {
            return [
                'sql' => $statement,
                'query_type' => QueryType::Write,
                'source' => $rule->match_type === SqlPolicyRuleMatchType::Exact ? 'custom_exact' : 'custom_shape',
                'rule' => $rule,
                'shape' => $shape,
            ];
        }

        return [
            'sql' => $statement,
            'query_type' => QueryType::Write,
            'source' => $this->settings->allowsEmergencySqlFallback()
                ? 'emergency_fallback'
                : 'unsupported',
            'rule' => null,
            'shape' => $shape,
        ];
    }

    /**
     * @param  array{signature:string,label:string,match_value:string}|null  $shape
     */
    private function matchingRule(DatabaseConnection $connection, string $sql, ?array $shape): ?SqlPolicyRule
    {
        $matchValues = [$this->analyzer->exactFingerprint($sql)];

        if ($shape !== null) {
            $matchValues[] = $shape['match_value'];
        }

        $scopeKeys = [
            SqlPolicyRuleScope::Workspace->key(null),
            SqlPolicyRuleScope::DatabaseConnection->key($connection->id),
            ...$connection->connectionGroups()
                ->pluck('connection_groups.id')
                ->map(fn (int $id): string => SqlPolicyRuleScope::ConnectionGroup->key($id))
                ->all(),
        ];

        /** @var Collection<int, SqlPolicyRule> $rules */
        $rules = SqlPolicyRule::query()
            ->where('database_driver', $connection->driver->value)
            ->where('is_enabled', true)
            ->whereIn('scope_key', $scopeKeys)
            ->whereIn('match_value', $matchValues)
            ->get();

        $matchingRules = $rules->filter(function (SqlPolicyRule $rule) use ($shape, $sql): bool {
            return match ($rule->match_type) {
                SqlPolicyRuleMatchType::Exact => hash_equals(
                    $rule->match_value,
                    $this->analyzer->exactFingerprint($sql),
                ),
                SqlPolicyRuleMatchType::Shape => $shape !== null
                    && hash_equals($rule->match_value, $shape['match_value']),
            };
        });

        return $matchingRules->first(fn (SqlPolicyRule $rule): bool => $rule->effect === SqlPolicyRuleEffect::Deny)
            ?? $matchingRules->sortBy(fn (SqlPolicyRule $rule): int => $rule->match_type === SqlPolicyRuleMatchType::Exact ? 0 : 1)->first();
    }
}
