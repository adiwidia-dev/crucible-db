<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'is_admin' => false,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Admin',
            'slug' => 'admin',
            'description' => 'Can manage Crucible DB and review every request.',
            'is_admin' => true,
        ]);
    }

    public function developer(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Developer',
            'slug' => 'developer',
            'description' => 'Can request database access based on assigned permissions.',
            'is_admin' => false,
        ]);
    }
}
