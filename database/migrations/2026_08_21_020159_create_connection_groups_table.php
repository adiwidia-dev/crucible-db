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
        Schema::create('connection_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('connection_group_database_connection', function (Blueprint $table) {
            $table->foreignId('connection_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('database_connection_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['connection_group_id', 'database_connection_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connection_group_database_connection');
        Schema::dropIfExists('connection_groups');
    }
};
