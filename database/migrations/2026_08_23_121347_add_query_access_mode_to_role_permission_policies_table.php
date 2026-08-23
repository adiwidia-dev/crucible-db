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
        Schema::table('role_database_permissions', function (Blueprint $table) {
            $table->string('query_access_mode')->default('read')->after('access_mode');
        });

        Schema::table('role_connection_group_policies', function (Blueprint $table) {
            $table->string('query_access_mode')->default('read')->after('access_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('role_database_permissions', function (Blueprint $table) {
            $table->dropColumn('query_access_mode');
        });

        Schema::table('role_connection_group_policies', function (Blueprint $table) {
            $table->dropColumn('query_access_mode');
        });
    }
};
