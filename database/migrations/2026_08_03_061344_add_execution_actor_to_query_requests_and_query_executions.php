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
            $table->foreignId('dispatched_by_id')
                ->nullable()
                ->after('approved_by_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('query_executions', function (Blueprint $table) {
            $table->foreignId('executed_by_id')
                ->nullable()
                ->after('query_request_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_executions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('executed_by_id');
        });

        Schema::table('query_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dispatched_by_id');
        });
    }
};
