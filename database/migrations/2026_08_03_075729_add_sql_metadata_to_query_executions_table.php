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
        Schema::table('query_executions', function (Blueprint $table) {
            $table->text('sql')->nullable()->after('executed_by_id');
            $table->string('query_type')->nullable()->after('sql')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_executions', function (Blueprint $table) {
            $table->dropColumn(['sql', 'query_type']);
        });
    }
};
