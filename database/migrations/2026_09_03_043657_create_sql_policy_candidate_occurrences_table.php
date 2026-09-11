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
        Schema::create('sql_policy_candidate_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sql_policy_candidate_id');
            $table->foreignId('query_request_id');
            $table->foreignId('query_request_statement_id')->nullable();
            $table->foreignId('database_connection_id')->nullable();
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();

            $table->foreign('sql_policy_candidate_id', 'policy_occurrence_candidate_fk')
                ->references('id')->on('sql_policy_candidates')->cascadeOnDelete();
            $table->foreign('query_request_id', 'policy_occurrence_request_fk')
                ->references('id')->on('query_requests')->cascadeOnDelete();
            $table->foreign('query_request_statement_id', 'policy_occurrence_statement_fk')
                ->references('id')->on('query_request_statements')->nullOnDelete();
            $table->foreign('database_connection_id', 'policy_occurrence_database_fk')
                ->references('id')->on('database_connections')->nullOnDelete();

            $table->unique(
                ['sql_policy_candidate_id', 'query_request_statement_id'],
                'sql_policy_candidate_occurrences_unique_statement',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sql_policy_candidate_occurrences');
    }
};
