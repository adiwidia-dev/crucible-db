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
        if ($this->hasQuerySessionForeignKey()) {
            return;
        }

        Schema::table('query_session_queries', function (Blueprint $table): void {
            $table->foreign('query_session_id')->references('id')->on('query_sessions')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->hasQuerySessionForeignKey()) {
            return;
        }

        Schema::table('query_session_queries', function (Blueprint $table): void {
            $table->dropForeign(['query_session_id']);
        });
    }

    private function hasQuerySessionForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('query_session_queries') as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === ['query_session_id']) {
                return true;
            }
        }

        return false;
    }
};
