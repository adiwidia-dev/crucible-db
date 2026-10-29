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
        Schema::table('auth_providers', function (Blueprint $table) {
            $table->string('issuer_url', 2048)->nullable()->after('tenant');
            $table->dropUnique(['provider']);
        });

        if (! Schema::hasIndex('user_identities', 'user_identities_user_id_index')) {
            Schema::table('user_identities', function (Blueprint $table) {
                $table->index('user_id');
            });
        }

        if (! Schema::hasIndex('user_identities', 'user_identities_auth_provider_id_index')) {
            Schema::table('user_identities', function (Blueprint $table) {
                $table->index('auth_provider_id');
            });
        }

        Schema::table('user_identities', function (Blueprint $table) {
            $table->dropUnique(['provider', 'provider_user_id']);
            $table->dropUnique(['user_id', 'provider']);
            $table->unique(['auth_provider_id', 'provider_user_id']);
            $table->unique(['user_id', 'auth_provider_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_identities', function (Blueprint $table) {
            $table->dropUnique(['auth_provider_id', 'provider_user_id']);
            $table->dropUnique(['user_id', 'auth_provider_id']);
            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });

        Schema::table('user_identities', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('auth_providers', function (Blueprint $table) {
            $table->unique('provider');
            $table->dropColumn('issuer_url');
        });
    }
};
