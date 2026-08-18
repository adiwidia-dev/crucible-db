<?php

use App\Enums\QueryRequestKind;
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
            ->where('request_kind', QueryRequestKind::QueryAccess->value)
            ->orderBy('id')
            ->eachById(function (object $queryRequest): void {
                DB::table('query_request_connections')->insertOrIgnore([
                    'query_request_id' => $queryRequest->id,
                    'database_connection_id' => $queryRequest->database_connection_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        DB::table('query_sessions')
            ->orderBy('id')
            ->eachById(function (object $querySession): void {
                DB::table('query_session_connections')->insertOrIgnore([
                    'query_session_id' => $querySession->id,
                    'database_connection_id' => $querySession->database_connection_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        DB::table('query_session_queries')
            ->orderBy('id')
            ->eachById(function (object $query): void {
                DB::table('query_session_queries')
                    ->where('id', $query->id)
                    ->update([
                        'database_connection_id' => DB::table('query_sessions')
                            ->where('id', $query->query_session_id)
                            ->value('database_connection_id'),
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('query_session_queries')->update([
            'database_connection_id' => null,
        ]);

        DB::table('query_session_connections')->delete();
        DB::table('query_request_connections')->delete();
    }
};
