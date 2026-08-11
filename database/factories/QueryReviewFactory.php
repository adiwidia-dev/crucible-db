<?php

namespace Database\Factories;

use App\Models\QueryRequest;
use App\Models\QueryReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryReview>
 */
class QueryReviewFactory extends Factory
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
            'reviewer_id' => User::factory(),
            'decision' => 'approved',
            'comment' => fake()->optional()->sentence(),
        ];
    }
}
