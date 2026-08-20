<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('role_database_permissions', function (Blueprint $table): void {
            $table->boolean('read_requires_approval')->default(true)->after('requires_approval');
            $table->boolean('write_requires_approval')->default(true)->after('read_requires_approval');
            $table->unsignedInteger('max_write_session_minutes')->nullable()->after('write_requires_approval');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('role_database_permissions', function (Blueprint $table): void {
            $table->dropColumn([
                'read_requires_approval',
                'write_requires_approval',
                'max_write_session_minutes',
            ]);
        });
    }
};
