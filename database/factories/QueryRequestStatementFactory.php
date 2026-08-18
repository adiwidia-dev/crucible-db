<?php

namespace Database\Factories;

use App\Enums\QueryType;
use App\Models\DatabaseConnection;
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
            'database_connection_id' => DatabaseConnection::factory(),
            'position' => 1,
            'sql' => 'select 1 as value',
            'query_type' => QueryType::Read,
        ];
    }
}
