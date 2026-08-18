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
        Schema::create('query_request_statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('query_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->text('sql');
            $table->string('query_type')->index();
            $table->timestamps();

            $table->unique(['query_request_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('query_request_statements');
    }
};
