<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('query_requests', function (Blueprint $table): void {
            $table->string('execution_source_ip_address', 45)->nullable()->after('dispatched_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('query_requests', function (Blueprint $table): void {
            $table->dropColumn('execution_source_ip_address');
        });
    }
};
