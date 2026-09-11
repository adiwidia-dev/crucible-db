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
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('audit_logs', function (Blueprint $table) use ($driver): void {
            $auditableId = $table->string('auditable_id', 64)->nullable();

            if ($driver === 'pgsql') {
                $auditableId->using('auditable_id::text');
            }

            $auditableId->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'The audit log identifier migration cannot be reversed safely because ULID and UUID audit entries cannot be converted to integers.',
        );
    }
};
