<?php

namespace Database\Factories;

use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuerySession>
 */
class QuerySessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'query_request_id' => QueryRequest::factory()->queryAccess(),
            'user_id' => User::factory(),
            'database_connection_id' => DatabaseConnection::factory(),
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'ended_at' => null,
        ];
    }
}
