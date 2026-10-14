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
        Schema::table('query_requests', function (Blueprint $table): void {
            $table->foreignId('copied_from_id')
                ->nullable()
                ->after('retry_of_id')
                ->constrained('query_requests')
                ->nullOnDelete();
            $table->index('copied_from_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_requests', function (Blueprint $table): void {
            $table->dropIndex(['copied_from_id']);
            $table->dropConstrainedForeignId('copied_from_id');
        });
    }
};
