<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');

            $table->string('name');
            $table->string('domain')->nullable();
            $table->uuid('api_key')->unique();
            $table->string('schema_name')->unique();

            // Telegram
            $table->bigInteger('telegram_chat_id')->nullable();
            $table->boolean('telegram_bot_connected')->default(false);

            // Payment providers
            $table->enum('payment_provider', ['yookassa', 'robokassa', 'cloudpayments'])
                ->default('yookassa');
            $table->string('yookassa_shop_id')->nullable();
            $table->string('yookassa_secret_key')->nullable();
            $table->string('robokassa_login')->nullable();
            $table->string('robokassa_password1')->nullable();
            $table->string('robokassa_password2')->nullable();

            // Subscription
            $table->enum('subscription_plan', ['micro', 'start', 'business', 'pro'])
                ->default('micro');
            $table->timestamp('subscription_expires_at')->nullable();

            // Widget settings
            $table->json('widget_config');

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
