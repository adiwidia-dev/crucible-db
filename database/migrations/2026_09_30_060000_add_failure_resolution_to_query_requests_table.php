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
            $table->foreignId('failure_resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('failure_resolved_at')->nullable();
            $table->string('failure_resolution', 32)->nullable();
            $table->text('failure_resolution_note')->nullable();
            $table->foreignId('replacement_query_request_id')->nullable()->constrained('query_requests')->nullOnDelete();
            $table->index(
                ['status', 'failure_resolved_at', 'completed_at'],
                'query_requests_failed_queue_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_requests', function (Blueprint $table): void {
            $table->dropIndex('query_requests_failed_queue_index');
            $table->dropForeign(['failure_resolved_by_id']);
            $table->dropForeign(['replacement_query_request_id']);
            $table->dropColumn([
                'failure_resolved_by_id',
                'failure_resolved_at',
                'failure_resolution',
                'failure_resolution_note',
                'replacement_query_request_id',
            ]);
        });
    }
};
