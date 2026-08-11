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
        Schema::create('role_database_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('database_connection_id')->constrained()->cascadeOnDelete();
            $table->string('access_mode')->default('none');
            $table->boolean('can_review')->default(false)->index();
            $table->boolean('requires_approval')->default(true);
            $table->timestamps();

            $table->unique(['role_id', 'database_connection_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_database_permissions');
    }
};
