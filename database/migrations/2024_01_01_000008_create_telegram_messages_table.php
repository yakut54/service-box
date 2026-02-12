<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shop_id')->constrained('shops')->onDelete('cascade');

            $table->bigInteger('telegram_message_id')->nullable();
            $table->bigInteger('telegram_chat_id');

            $table->enum('type', [
                'order_notification',
                'booking_notification',
                'booking_reminder',
                'subscription_expiring',
                'subscription_expired',
                'payment_success',
                'system_notification',
            ]);

            $table->uuid('entity_id')->nullable();
            $table->string('entity_type')->nullable();

            $table->text('message_text');
            $table->json('inline_keyboard')->nullable();

            $table->enum('status', ['pending', 'sent', 'failed', 'delivered'])->default('pending');
            $table->text('error_message')->nullable();

            $table->string('user_action')->nullable();
            $table->timestamp('user_action_at')->nullable();

            $table->timestamps();

            $table->index('shop_id');
            $table->index('telegram_chat_id');
            $table->index(['entity_type', 'entity_id']);
            $table->index('type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
    }
};
