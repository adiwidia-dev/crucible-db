<?php

namespace Database\Factories;

use App\Enums\ExecutionStatus;
use App\Enums\QueryType;
use App\Models\QueryExecution;
use App\Models\QueryRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryExecution>
 */
class QueryExecutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'query_request_id' => QueryRequest::factory(),
            'sql' => 'select 1 as value',
            'query_type' => QueryType::Read,
            'status' => ExecutionStatus::Succeeded,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 10,
            'row_count' => 1,
            'sample_rows' => [['value' => 1]],
        ];
    }
}
