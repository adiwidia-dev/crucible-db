<?php

namespace Database\Factories;

use App\Models\QueryResultSnapshot;
use App\Models\QuerySessionQuery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryResultSnapshot>
 */
class QueryResultSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'query_session_query_id' => QuerySessionQuery::factory(),
            'rows' => [['value' => 1]],
            'row_count' => 1,
            'byte_count' => 11,
            'expires_at' => now()->addHour(),
        ];
    }
}
