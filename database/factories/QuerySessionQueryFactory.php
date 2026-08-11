<?php

namespace Database\Factories;

use App\Enums\ExecutionStatus;
use App\Enums\QueryType;
use App\Models\QuerySession;
use App\Models\QuerySessionQuery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuerySessionQuery>
 */
class QuerySessionQueryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'query_session_id' => QuerySession::factory(),
            'user_id' => User::factory(),
            'sql' => 'select 1 as value',
            'query_type' => QueryType::Read,
            'status' => ExecutionStatus::Succeeded,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 1,
            'row_count' => 1,
            'sample_rows' => [['value' => 1]],
            'error_message' => null,
        ];
    }
}
