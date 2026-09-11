<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_SETTING = 'sql_all_statement_families_enabled';

    private const STATEMENT_FAMILY_SETTINGS = [
        'sql_read_queries_enabled',
        'sql_insert_enabled',
        'sql_update_enabled',
        'sql_delete_enabled',
        'sql_create_table_enabled',
        'sql_alter_table_enabled',
        'sql_drop_table_enabled',
        'sql_truncate_table_enabled',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('application_settings')) {
            return;
        }

        $legacySetting = DB::table('application_settings')
            ->where('key', self::LEGACY_SETTING)
            ->first();

        if ($legacySetting === null) {
            return;
        }

        if ($this->isEnabled($legacySetting->value)) {
            $now = now();

            foreach (self::STATEMENT_FAMILY_SETTINGS as $settingKey) {
                DB::table('application_settings')->updateOrInsert(
                    ['key' => $settingKey],
                    fn (bool $exists): array => $exists
                        ? ['value' => Crypt::encryptString('1'), 'updated_at' => $now]
                        : ['value' => Crypt::encryptString('1'), 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        DB::table('application_settings')
            ->where('key', self::LEGACY_SETTING)
            ->delete();
    }

    /**
     * Individual family settings remain compatible with releases that supported the legacy master setting.
     */
    public function down(): void {}

    private function isEnabled(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        try {
            $value = Crypt::decryptString($value);
        } catch (DecryptException) {
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
};
