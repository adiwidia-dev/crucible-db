<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('query_requests')
            ->where('request_kind', 'single_execution')
            ->where('sql', '<>', '')
            ->orderBy('id')
            ->each(function (object $queryRequest): void {
                DB::table('query_request_statements')->insert([
                    'query_request_id' => $queryRequest->id,
                    'position' => 1,
                    'sql' => $queryRequest->sql,
                    'query_type' => $queryRequest->query_type,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('query_request_statements')->delete();
    }
};
