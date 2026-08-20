<?php

namespace Database\Factories;

use App\Models\DatabaseConnection;
use App\Models\NotificationSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationSubscription>
 */
class NotificationSubscriptionFactory extends Factory
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
            'subscribable_type' => DatabaseConnection::class,
            'subscribable_id' => DatabaseConnection::factory(),
        ];
    }
}
