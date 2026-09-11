<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_connections', function (Blueprint $table): void {
            $table->string('tls_mode', 32)->default('preferred')->after('ssl_mode');
            $table->text('tls_ca_certificate')->nullable()->after('tls_mode');
            $table->text('tls_client_certificate')->nullable()->after('tls_ca_certificate');
            $table->text('tls_client_key')->nullable()->after('tls_client_certificate');
        });

        DB::table('database_connections')
            ->where('driver', 'pgsql')
            ->whereNotNull('ssl_mode')
            ->orderBy('id')
            ->eachById(function (object $connection): void {
                DB::table('database_connections')
                    ->where('id', $connection->id)
                    ->update([
                        'tls_mode' => match ($connection->ssl_mode) {
                            'disable', 'disabled' => 'disabled',
                            'require', 'required' => 'required',
                            'verify-ca', 'verify_ca' => 'verify_ca',
                            'verify-full', 'verify_identity' => 'verify_identity',
                            default => 'preferred',
                        },
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('database_connections', function (Blueprint $table): void {
            $table->dropColumn([
                'tls_mode',
                'tls_ca_certificate',
                'tls_client_certificate',
                'tls_client_key',
            ]);
        });
    }
};
