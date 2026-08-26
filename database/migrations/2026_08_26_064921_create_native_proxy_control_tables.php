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
        Schema::create('native_proxy_leases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('query_session_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('query_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('database_connection_id')->constrained()->restrictOnDelete();
            $table->string('protocol', 16);
            $table->string('access_mode', 16);
            $table->string('synthetic_username', 128)->unique();
            $table->text('synthetic_password_hash');
            $table->text('protocol_auth_secret');
            $table->unsignedInteger('credential_version')->default(1);
            $table->string('status', 32)->index();
            $table->unsignedSmallInteger('max_concurrent_connections')->default(3);
            $table->timestamp('credentials_revealed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->text('revocation_reason')->nullable();
            $table->foreignId('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['user_id', 'status']);
            $table->index(['database_connection_id', 'status']);
        });

        Schema::create('native_proxy_device_authorizations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('lease_id')->constrained('native_proxy_leases')->cascadeOnDelete();
            $table->string('device_code_hash', 64)->unique();
            $table->string('user_code_hash', 64)->unique();
            $table->string('cli_version', 64);
            $table->string('operating_system', 64);
            $table->string('architecture', 64);
            $table->string('device_label', 255)->nullable();
            $table->unsignedSmallInteger('polling_interval_seconds')->default(5);
            $table->unsignedInteger('poll_count')->default(0);
            $table->string('status', 32)->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('authorized_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('denied_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['lease_id', 'status', 'expires_at']);
        });

        Schema::create('native_proxy_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('lease_id')->constrained('native_proxy_leases')->cascadeOnDelete();
            $table->foreignUlid('device_authorization_id')->constrained('native_proxy_device_authorizations')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('scope', 32)->default('native_tunnel');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(['lease_id', 'revoked_at', 'expires_at']);
            $table->index(['device_authorization_id', 'revoked_at']);
        });

        Schema::create('native_proxy_auth_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('lease_id')->constrained('native_proxy_leases')->cascadeOnDelete();
            $table->foreignUlid('token_id')->constrained('native_proxy_tokens')->cascadeOnDelete();
            $table->foreignUlid('device_authorization_id')->constrained('native_proxy_device_authorizations')->cascadeOnDelete();
            $table->string('proxy_instance_id', 128);
            $table->string('protocol', 16);
            $table->unsignedInteger('credential_version');
            $table->string('proxy_connection_id', 128)->unique();
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::create('native_proxy_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('proxy_connection_id', 128)->unique();
            $table->foreignUlid('lease_id')->constrained('native_proxy_leases')->restrictOnDelete();
            $table->foreignId('query_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('query_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('database_connection_id')->constrained()->restrictOnDelete();
            $table->string('protocol', 16);
            $table->string('proxy_instance_id', 128);
            $table->string('client_application', 128)->nullable();
            $table->string('client_version', 128)->nullable();
            $table->string('cli_version', 64)->nullable();
            $table->string('operating_system', 64)->nullable();
            $table->string('architecture', 64)->nullable();
            $table->string('upstream_tls_mode', 32);
            $table->boolean('upstream_tls_verified')->default(false);
            $table->string('status', 32)->index();
            $table->timestamp('reservation_expires_at')->nullable()->index();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('authenticated_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('disconnected_at')->nullable()->index();
            $table->text('disconnect_reason')->nullable();
            $table->unsignedBigInteger('bytes_received')->default(0);
            $table->unsignedBigInteger('bytes_sent')->default(0);
            $table->unsignedInteger('statement_count')->default(0);
            $table->timestamps();

            $table->index(['lease_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'last_activity_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('native_proxy_connections');
        Schema::dropIfExists('native_proxy_auth_attempts');
        Schema::dropIfExists('native_proxy_tokens');
        Schema::dropIfExists('native_proxy_device_authorizations');
        Schema::dropIfExists('native_proxy_leases');
    }
};
