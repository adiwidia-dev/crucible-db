<?php

namespace Database\Seeders;

use App\Enums\AccessMode;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Models\Role;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $adminRole = Role::query()->firstOrCreate(
            ['slug' => 'admin'],
            [
                'name' => 'Admin',
                'description' => 'Can manage Crucible DB and review every request.',
                'is_admin' => true,
            ],
        );

        $developerRole = Role::query()->firstOrCreate(
            ['slug' => 'developer'],
            [
                'name' => 'Developer',
                'description' => 'Can request database access based on assigned permissions.',
                'is_admin' => false,
            ],
        );

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'role_id' => $adminRole->id,
                'name' => 'Crucible Admin',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );

        $developer = User::query()->firstOrCreate(
            ['email' => 'developer@example.com'],
            [
                'role_id' => $developerRole->id,
                'name' => 'Crucible Developer',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );

        $admin->roles()->syncWithoutDetaching([$adminRole->id => ['priority' => 100]]);
        $developer->roles()->syncWithoutDetaching([$developerRole->id => ['priority' => 100]]);

        $postgres = DatabaseConnection::query()->updateOrCreate(
            ['name' => 'Local Target PostgreSQL'],
            [
                'created_by_id' => $admin->id,
                'driver' => DatabaseDriver::PostgreSql,
                'host' => 'target-postgres',
                'port' => 5432,
                'database' => 'crucible_target',
                'username' => 'crucible',
                'password' => 'crucible',
                'ssl_mode' => null,
                'is_active' => true,
            ],
        );

        $mysql = DatabaseConnection::query()->updateOrCreate(
            ['name' => 'Local Target MySQL'],
            [
                'created_by_id' => $admin->id,
                'driver' => DatabaseDriver::MySql,
                'host' => 'target-mysql',
                'port' => 3306,
                'database' => 'crucible_target',
                'username' => 'crucible',
                'password' => 'crucible',
                'ssl_mode' => null,
                'is_active' => true,
            ],
        );

        foreach ([$postgres, $mysql] as $connection) {
            RoleDatabasePermission::query()->updateOrCreate(
                [
                    'role_id' => $developerRole->id,
                    'database_connection_id' => $connection->id,
                ],
                [
                    'access_mode' => AccessMode::Read,
                    'can_review' => false,
                    'requires_approval' => true,
                ],
            );
        }
    }
}
