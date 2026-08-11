<?php

namespace Database\Factories;

use App\Models\AuthProvider;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserIdentity>
 */
class UserIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'auth_provider_id' => AuthProvider::factory(),
            'provider' => 'google',
            'provider_user_id' => fake()->uuid(),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'avatar' => null,
        ];
    }
}
