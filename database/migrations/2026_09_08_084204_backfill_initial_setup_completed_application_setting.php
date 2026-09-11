<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('application_settings') || ! DB::table('users')->exists()) {
            return;
        }

        DB::table('application_settings')->insertOrIgnore([
            'key' => 'initial_setup_completed',
            'value' => Crypt::encryptString('1'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('application_settings')
            ->where('key', 'initial_setup_completed')
            ->delete();
    }
};
