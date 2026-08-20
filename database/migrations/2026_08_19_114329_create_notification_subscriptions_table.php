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
        Schema::create('notification_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subscribable_type');
            $table->unsignedBigInteger('subscribable_id');
            $table->timestamps();

            $table->index(
                ['subscribable_type', 'subscribable_id'],
                'notification_subscribable_index',
            );
            $table->unique(
                ['user_id', 'subscribable_type', 'subscribable_id'],
                'notification_subscriptions_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_subscriptions');
    }
};
