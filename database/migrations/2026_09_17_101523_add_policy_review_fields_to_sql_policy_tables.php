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
        Schema::table('sql_policy_candidates', function (Blueprint $table) {
            $table->text('resolution_comment')->nullable()->after('resolved_rule_id');
        });

        Schema::table('sql_policy_candidate_occurrences', function (Blueprint $table) {
            $table->foreignId('review_requested_by_id')
                ->nullable()
                ->after('database_connection_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('review_requested_at')->nullable()->after('review_requested_by_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sql_policy_candidate_occurrences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_requested_by_id');
            $table->dropColumn('review_requested_at');
        });

        Schema::table('sql_policy_candidates', function (Blueprint $table) {
            $table->dropColumn('resolution_comment');
        });
    }
};
