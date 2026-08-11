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
        Schema::create('query_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('database_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('sql');
            $table->string('query_type');
            $table->string('status')->index();
            $table->boolean('requires_approval')->default(true)->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dispatched_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->json('result_summary')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['requester_id', 'status']);
            $table->index(['database_connection_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('query_requests');
    }
};
