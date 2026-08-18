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
        Schema::create('query_request_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('query_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('database_connection_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['query_request_id', 'database_connection_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('query_request_connections');
    }
};
