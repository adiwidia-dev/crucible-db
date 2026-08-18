<?php

namespace Database\Factories;

use App\Enums\QueryType;
use App\Models\QueryRequest;
use App\Models\QueryRequestStatement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryRequestStatement>
 */
class QueryRequestStatementFactory extends Factory
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
            'position' => 1,
            'sql' => 'select 1 as value',
            'query_type' => QueryType::Read,
        ];
    }
}
