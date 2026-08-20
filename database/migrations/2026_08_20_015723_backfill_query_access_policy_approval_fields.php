<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('role_database_permissions')->update([
            'read_requires_approval' => DB::raw('requires_approval'),
            'write_requires_approval' => DB::raw('requires_approval'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Approval settings remain intentionally preserved when rolling back the schema migration.
    }
};
