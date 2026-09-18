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
            $table->mediumText('sql')->change();
        });

        Schema::table('query_request_statements', function (Blueprint $table): void {
            $table->mediumText('sql')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_requests', function (Blueprint $table): void {
            $table->text('sql')->change();
        });

        Schema::table('query_request_statements', function (Blueprint $table): void {
            $table->text('sql')->change();
        });
    }
};
