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
        Schema::create('sql_policy_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('database_driver')->index();
            $table->char('exact_fingerprint', 64);
            $table->text('canonical_sql');
            $table->string('shape_signature')->nullable()->index();
            $table->string('shape_label')->nullable();
            $table->string('resolution')->nullable()->index();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('resolved_rule_id')->nullable()->index();
            $table->timestamp('first_seen_at')->index();
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();

            $table->unique(['database_driver', 'exact_fingerprint']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sql_policy_candidates');
    }
};
