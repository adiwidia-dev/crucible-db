<?php

namespace Database\Factories;

use App\Models\ConnectionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectionGroup>
 */
class ConnectionGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
        ];
    }
}
