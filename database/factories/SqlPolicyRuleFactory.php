<?php

namespace Database\Factories;

use App\Enums\DatabaseDriver;
use App\Enums\SqlPolicyRuleEffect;
use App\Enums\SqlPolicyRuleMatchType;
use App\Enums\SqlPolicyRuleScope;
use App\Models\SqlPolicyRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SqlPolicyRule>
 */
class SqlPolicyRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'database_driver' => DatabaseDriver::PostgreSql,
            'effect' => SqlPolicyRuleEffect::Allow,
            'match_type' => SqlPolicyRuleMatchType::Exact,
            'match_value' => hash('sha256', 'CREATE INDEX sample_index ON samples (id)'),
            'canonical_sql' => 'CREATE INDEX sample_index ON samples (id)',
            'shape_signature' => null,
            'shape_label' => null,
            'scope_type' => SqlPolicyRuleScope::Workspace,
            'scope_id' => null,
            'scope_key' => SqlPolicyRuleScope::Workspace->key(null),
            'created_by_id' => User::factory(),
            'source_candidate_id' => null,
            'is_enabled' => true,
        ];
    }
}
