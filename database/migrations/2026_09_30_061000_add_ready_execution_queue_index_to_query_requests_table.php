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
            $table->index(
                ['status', 'request_kind', 'dispatched_at', 'approved_at'],
                'query_requests_ready_execution_queue_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_requests', function (Blueprint $table): void {
            $table->dropIndex('query_requests_ready_execution_queue_index');
        });
    }
};
