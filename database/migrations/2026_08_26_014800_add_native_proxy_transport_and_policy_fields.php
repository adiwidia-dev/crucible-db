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
        Schema::table('query_requests', function (Blueprint $table): void {
            $table->string('access_transport', 32)->default('browser')->index()->after('request_kind');
        });

        Schema::table('database_connections', function (Blueprint $table): void {
            $table->boolean('native_proxy_enabled')->default(false)->after('is_active');
        });

        Schema::table('role_database_permissions', function (Blueprint $table): void {
            $table->string('native_proxy_access_mode', 16)->default('none')->after('query_access_mode');
        });

        Schema::table('role_connection_group_policies', function (Blueprint $table): void {
            $table->string('native_proxy_access_mode', 16)->default('none')->after('query_access_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('role_connection_group_policies', function (Blueprint $table): void {
            $table->dropColumn('native_proxy_access_mode');
        });

        Schema::table('role_database_permissions', function (Blueprint $table): void {
            $table->dropColumn('native_proxy_access_mode');
        });

        Schema::table('database_connections', function (Blueprint $table): void {
            $table->dropColumn('native_proxy_enabled');
        });

        Schema::table('query_requests', function (Blueprint $table): void {
            $table->dropIndex(['access_transport']);
            $table->dropColumn('access_transport');
        });
    }
};
