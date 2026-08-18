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
        Schema::table('query_executions', function (Blueprint $table): void {
            $table->foreignId('query_request_statement_id')
                ->nullable()
                ->after('query_request_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_executions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('query_request_statement_id');
        });
    }
};
