<?php

namespace Database\Factories;

use App\Enums\DatabaseDriver;
use App\Models\SqlPolicyCandidate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SqlPolicyCandidate>
 */
class SqlPolicyCandidateFactory extends Factory
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
            'exact_fingerprint' => hash('sha256', 'CREATE INDEX sample_index ON samples (id)'),
            'canonical_sql' => 'CREATE INDEX sample_index ON samples (id)',
            'shape_signature' => 'pgsql.create_index.standard.v1',
            'shape_label' => 'CREATE INDEX',
            'resolution' => null,
            'resolved_by_id' => null,
            'resolved_rule_id' => null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
