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
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('role_id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->foreignId('invited_by_id')->nullable()->after('email_verified_at')->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable()->after('invited_by_id');
            $table->timestamp('invitation_accepted_at')->nullable()->after('invited_at')->index();
            $table->string('invitation_token_hash', 64)->nullable()->after('invitation_accepted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by_id');
            $table->dropIndex(['invitation_accepted_at']);
            $table->dropColumn([
                'first_name',
                'last_name',
                'invited_at',
                'invitation_accepted_at',
                'invitation_token_hash',
            ]);
        });
    }
};
