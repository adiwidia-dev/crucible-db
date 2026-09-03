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
        Schema::create('sql_policy_rules', function (Blueprint $table) {
            $table->id();
            $table->string('database_driver')->index();
            $table->string('effect')->index();
            $table->string('match_type')->index();
            $table->char('match_value', 64);
            $table->text('canonical_sql')->nullable();
            $table->string('shape_signature')->nullable();
            $table->string('shape_label')->nullable();
            $table->string('scope_type')->index();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('scope_key')->index();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_candidate_id')->nullable()->constrained('sql_policy_candidates')->nullOnDelete();
            $table->boolean('is_enabled')->default(true)->index();
            $table->timestamps();

            $table->unique(
                ['database_driver', 'match_type', 'match_value', 'scope_key'],
                'sql_policy_rules_unique_match_scope',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sql_policy_rules');
    }
};
