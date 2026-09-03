<?php

namespace Database\Factories;

use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\QueryRequestStatement;
use App\Models\SqlPolicyCandidate;
use App\Models\SqlPolicyCandidateOccurrence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SqlPolicyCandidateOccurrence>
 */
class SqlPolicyCandidateOccurrenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sql_policy_candidate_id' => SqlPolicyCandidate::factory(),
            'query_request_id' => QueryRequest::factory(),
            'query_request_statement_id' => QueryRequestStatement::factory(),
            'database_connection_id' => DatabaseConnection::factory(),
            'last_seen_at' => now(),
        ];
    }
}
