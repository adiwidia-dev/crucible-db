<?php

namespace Database\Factories;

use App\Enums\AccessMode;
use App\Models\ConnectionGroup;
use App\Models\Role;
use App\Models\RoleConnectionGroupPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleConnectionGroupPolicy>
 */
class RoleConnectionGroupPolicyFactory extends Factory
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
            'connection_group_id' => ConnectionGroup::factory(),
            'access_mode' => AccessMode::Read,
            'query_access_mode' => AccessMode::Read,
            'native_proxy_access_mode' => AccessMode::None,
            'can_review' => false,
            'requires_approval' => true,
            'read_requires_approval' => true,
            'write_requires_approval' => true,
            'max_write_session_minutes' => null,
        ];
    }
}
