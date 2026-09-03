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
        Schema::table('query_executions', function (Blueprint $table): void {
            $table->foreignId('native_query_session_query_id')
                ->nullable()
                ->constrained('query_session_queries')
                ->nullOnDelete();
            $table->index('native_query_session_query_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_executions', function (Blueprint $table): void {
            $table->dropIndex(['native_query_session_query_id']);
            $table->dropConstrainedForeignId('native_query_session_query_id');
        });
    }
};
