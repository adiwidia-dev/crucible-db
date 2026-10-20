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
        Schema::table('query_session_queries', function (Blueprint $table) {
            $table->uuid('execution_token')->nullable()->after('status')->unique();
            $table->unsignedSmallInteger('timeout_seconds')->nullable()->after('execution_token');
            $table->string('backend_process_id', 64)->nullable()->after('timeout_seconds');
            $table->string('backend_application_name', 64)->nullable()->after('backend_process_id');
            $table->timestamp('cancel_requested_at')->nullable()->after('backend_application_name');
            $table->index(['native_proxy_connection_id', 'status', 'started_at'], 'query_session_queries_transport_status_started_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_session_queries', function (Blueprint $table) {
            $table->dropIndex('query_session_queries_transport_status_started_index');
            $table->dropUnique(['execution_token']);
            $table->dropColumn([
                'execution_token',
                'timeout_seconds',
                'backend_process_id',
                'backend_application_name',
                'cancel_requested_at',
            ]);
        });
    }
};
