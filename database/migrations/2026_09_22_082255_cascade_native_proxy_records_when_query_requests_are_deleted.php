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
        Schema::table('native_proxy_leases', function (Blueprint $table): void {
            $table->dropForeign(['query_session_id']);
            $table->dropForeign(['query_request_id']);

            $table->foreign('query_session_id')->references('id')->on('query_sessions')->cascadeOnDelete();
            $table->foreign('query_request_id')->references('id')->on('query_requests')->cascadeOnDelete();
        });

        Schema::table('native_proxy_connections', function (Blueprint $table): void {
            $table->dropForeign(['lease_id']);
            $table->dropForeign(['query_session_id']);
            $table->dropForeign(['query_request_id']);

            $table->foreign('lease_id')->references('id')->on('native_proxy_leases')->cascadeOnDelete();
            $table->foreign('query_session_id')->references('id')->on('query_sessions')->cascadeOnDelete();
            $table->foreign('query_request_id')->references('id')->on('query_requests')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('native_proxy_connections', function (Blueprint $table): void {
            $table->dropForeign(['lease_id']);
            $table->dropForeign(['query_session_id']);
            $table->dropForeign(['query_request_id']);

            $table->foreign('lease_id')->references('id')->on('native_proxy_leases')->restrictOnDelete();
            $table->foreign('query_session_id')->references('id')->on('query_sessions')->restrictOnDelete();
            $table->foreign('query_request_id')->references('id')->on('query_requests')->restrictOnDelete();
        });

        Schema::table('native_proxy_leases', function (Blueprint $table): void {
            $table->dropForeign(['query_session_id']);
            $table->dropForeign(['query_request_id']);

            $table->foreign('query_session_id')->references('id')->on('query_sessions')->restrictOnDelete();
            $table->foreign('query_request_id')->references('id')->on('query_requests')->restrictOnDelete();
        });
    }
};
