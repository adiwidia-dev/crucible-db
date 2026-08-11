<?php

namespace Database\Factories;

use App\Enums\AccessMode;
use App\Models\DatabaseConnection;
use App\Models\Role;
use App\Models\RoleDatabasePermission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleDatabasePermission>
 */
class RoleDatabasePermissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role_id' => Role::factory(),
            'database_connection_id' => DatabaseConnection::factory(),
            'access_mode' => AccessMode::Read,
            'can_review' => false,
            'requires_approval' => true,
        ];
    }

    public function write(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_mode' => AccessMode::Write,
        ]);
    }

    public function reviewer(): static
    {
        return $this->state(fn (array $attributes) => [
            'can_review' => true,
        ]);
    }

    public function bypassApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'requires_approval' => false,
        ]);
    }
}
