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
        Schema::table('query_executions', function (Blueprint $table) {
            $table->foreignId('query_session_query_id')
                ->nullable()
                ->after('query_request_id')
                ->constrained('query_session_queries')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_executions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('query_session_query_id');
        });
    }
};
