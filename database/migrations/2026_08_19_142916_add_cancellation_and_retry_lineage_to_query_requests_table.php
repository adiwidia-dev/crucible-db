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
        Schema::table('query_requests', function (Blueprint $table) {
            $table->foreignId('cancelled_by_id')
                ->nullable()
                ->after('dispatched_by_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('retry_of_id')
                ->nullable()
                ->after('database_connection_id')
                ->constrained('query_requests')
                ->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');

            $table->index('retry_of_id');
            $table->index('cancelled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_requests', function (Blueprint $table) {
            $table->dropIndex(['retry_of_id']);
            $table->dropIndex(['cancelled_at']);
            $table->dropConstrainedForeignId('cancelled_by_id');
            $table->dropConstrainedForeignId('retry_of_id');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
