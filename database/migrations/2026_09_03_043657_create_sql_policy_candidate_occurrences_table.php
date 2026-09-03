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
            $table->foreignId('sql_policy_candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('query_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('query_request_statement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('database_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();

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
