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
        DB::table('query_request_statements')
            ->whereNull('database_connection_id')
            ->orderBy('id')
            ->each(function (object $statement): void {
                $databaseConnectionId = DB::table('query_requests')
                    ->where('id', $statement->query_request_id)
                    ->value('database_connection_id');

                DB::table('query_request_statements')
                    ->where('id', $statement->id)
                    ->update(['database_connection_id' => $databaseConnectionId]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('query_request_statements')->update([
            'database_connection_id' => null,
        ]);
    }
};
