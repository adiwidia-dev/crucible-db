<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('query_session_queries', function (Blueprint $table): void {
            $table->foreignUlid('native_proxy_connection_id')->nullable()->after('query_session_id')->constrained('native_proxy_connections')->nullOnDelete();
            $table->string('native_protocol_command', 32)->nullable()->after('sql');
            $table->string('native_sql_fingerprint', 64)->nullable()->after('native_protocol_command');
            $table->unsignedSmallInteger('native_parameter_count')->default(0)->after('native_sql_fingerprint');
            $table->index(['native_proxy_connection_id', 'status']);
        });

        Schema::table('query_executions', function (Blueprint $table): void {
            $table->foreignUlid('native_proxy_connection_id')->nullable()->after('query_request_id')->constrained('native_proxy_connections')->nullOnDelete();
            $table->string('native_protocol_command', 32)->nullable()->after('sql');
            $table->string('native_sql_fingerprint', 64)->nullable()->after('native_protocol_command');
            $table->unsignedSmallInteger('native_parameter_count')->default(0)->after('native_sql_fingerprint');
            $table->index(['native_proxy_connection_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('query_executions', function (Blueprint $table): void {
            $table->dropIndex(['native_proxy_connection_id', 'status']);
            $table->dropConstrainedForeignId('native_proxy_connection_id');
            $table->dropColumn(['native_protocol_command', 'native_sql_fingerprint', 'native_parameter_count']);
        });

        Schema::table('query_session_queries', function (Blueprint $table): void {
            $table->dropIndex(['native_proxy_connection_id', 'status']);
            $table->dropConstrainedForeignId('native_proxy_connection_id');
            $table->dropColumn(['native_protocol_command', 'native_sql_fingerprint', 'native_parameter_count']);
        });
    }
};
