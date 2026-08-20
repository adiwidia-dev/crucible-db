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
            $table->string('requested_access_mode')->nullable()->after('request_kind');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_requests', function (Blueprint $table): void {
            $table->dropColumn('requested_access_mode');
        });
    }
};
