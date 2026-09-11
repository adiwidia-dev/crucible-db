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
        Schema::table('query_requests', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1)->after('status');
        });

        Schema::table('query_reviews', function (Blueprint $table) {
            $table->unsignedInteger('query_request_revision')->default(1)->after('query_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_reviews', function (Blueprint $table) {
            $table->dropColumn('query_request_revision');
        });

        Schema::table('query_requests', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
