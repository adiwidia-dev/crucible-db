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
        Schema::create('query_result_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_session_query_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->longText('rows');
            $table->unsignedInteger('row_count');
            $table->unsignedBigInteger('byte_count');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('query_result_snapshots');
    }
};
