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
        Schema::table('native_proxy_leases', function (Blueprint $table): void {
            $table->string('credential_creation_idempotency_key', 128)->nullable()->unique()->after('protocol_auth_secret');
            $table->text('synthetic_password_hash')->nullable()->change();
            $table->text('protocol_auth_secret')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('native_proxy_leases', function (Blueprint $table): void {
            $table->dropUnique(['credential_creation_idempotency_key']);
            $table->dropColumn('credential_creation_idempotency_key');
            $table->text('synthetic_password_hash')->nullable(false)->change();
            $table->text('protocol_auth_secret')->nullable(false)->change();
        });
    }
};
