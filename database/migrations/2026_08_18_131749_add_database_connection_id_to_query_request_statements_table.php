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
        Schema::table('query_request_statements', function (Blueprint $table): void {
            $table->foreignId('database_connection_id')
                ->nullable()
                ->after('query_request_id')
                ->constrained()
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_request_statements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('database_connection_id');
        });
    }
};
