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
        Schema::create('role_connection_group_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_group_id')->constrained()->cascadeOnDelete();
            $table->string('access_mode');
            $table->boolean('can_review')->default(false);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('read_requires_approval')->default(true);
            $table->boolean('write_requires_approval')->default(true);
            $table->unsignedSmallInteger('max_write_session_minutes')->nullable();
            $table->timestamps();

            $table->unique(['role_id', 'connection_group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_connection_group_policies');
    }
};
