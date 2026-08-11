<?php

namespace Database\Factories;

use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryRequest>
 */
class QueryRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requester_id' => User::factory(),
            'database_connection_id' => DatabaseConnection::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->sentence(),
            'sql' => 'select 1 as value',
            'query_type' => QueryType::Read,
            'request_kind' => QueryRequestKind::SingleExecution,
            'status' => QueryRequestStatus::PendingReview,
            'requires_approval' => true,
            'scheduled_at' => null,
            'access_duration_minutes' => null,
        ];
    }

    public function queryAccess(): static
    {
        return $this->state(fn (array $attributes) => [
            'sql' => '',
            'query_type' => QueryType::Read,
            'request_kind' => QueryRequestKind::QueryAccess,
            'access_duration_minutes' => 60,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QueryRequestStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QueryRequestStatus::Scheduled,
            'approved_at' => now(),
            'scheduled_at' => now()->addHour(),
        ]);
    }
}
