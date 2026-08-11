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
            $table->index(['status', 'scheduled_at']);
            $table->index(['database_connection_id', 'created_at']);
        });

        Schema::table('query_sessions', function (Blueprint $table): void {
            $table->index(['query_request_id', 'started_at']);
            $table->index(['expires_at', 'ended_at']);
        });

        Schema::table('query_session_queries', function (Blueprint $table): void {
            $table->boolean('result_truncated')->default(false)->after('row_count');
            $table->index(['query_session_id', 'started_at']);
        });

        Schema::table('query_executions', function (Blueprint $table): void {
            $table->boolean('result_truncated')->default(false)->after('row_count');
            $table->index(['query_request_id', 'started_at']);
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index('created_at');
            $table->index(['action', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['action', 'created_at']);
        });

        Schema::table('query_executions', function (Blueprint $table): void {
            $table->dropIndex(['query_request_id', 'started_at']);
            $table->dropColumn('result_truncated');
        });

        Schema::table('query_session_queries', function (Blueprint $table): void {
            $table->dropIndex(['query_session_id', 'started_at']);
            $table->dropColumn('result_truncated');
        });

        Schema::table('query_sessions', function (Blueprint $table): void {
            $table->dropIndex(['query_request_id', 'started_at']);
            $table->dropIndex(['expires_at', 'ended_at']);
        });

        Schema::table('query_requests', function (Blueprint $table): void {
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropIndex(['database_connection_id', 'created_at']);
        });
    }
};
