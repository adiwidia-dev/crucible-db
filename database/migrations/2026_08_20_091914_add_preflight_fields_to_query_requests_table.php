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
            $table->string('preflight_status')->default('not_run')->after('requires_approval')->index();
            $table->json('preflight_report')->nullable()->after('preflight_status');
            $table->timestamp('preflight_checked_at')->nullable()->after('preflight_report');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_requests', function (Blueprint $table): void {
            $table->dropIndex(['preflight_status']);
            $table->dropColumn([
                'preflight_status',
                'preflight_report',
                'preflight_checked_at',
            ]);
        });
    }
};
