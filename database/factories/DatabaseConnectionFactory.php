<?php

namespace Database\Factories;

use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseConnection>
 */
class DatabaseConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $driver = fake()->randomElement(DatabaseDriver::cases());

        return [
            'name' => fake()->unique()->company().' Reporting',
            'driver' => $driver,
            'host' => '127.0.0.1',
            'port' => $driver->defaultPort(),
            'database' => 'crucible_target',
            'username' => 'crucible',
            'password' => 'crucible',
            'ssl_mode' => null,
            'is_active' => true,
        ];
    }

    public function postgresql(): static
    {
        return $this->state(fn (array $attributes) => [
            'driver' => DatabaseDriver::PostgreSql,
            'port' => DatabaseDriver::PostgreSql->defaultPort(),
        ]);
    }

    public function mysql(): static
    {
        return $this->state(fn (array $attributes) => [
            'driver' => DatabaseDriver::MySql,
            'port' => DatabaseDriver::MySql->defaultPort(),
        ]);
    }
}
