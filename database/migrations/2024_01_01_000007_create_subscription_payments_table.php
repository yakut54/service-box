<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shop_id')->constrained('shops')->onDelete('cascade');

            $table->string('payment_id')->unique();
            $table->string('plan');
            $table->integer('amount');
            $table->string('currency')->default('RUB');

            $table->enum('status', ['pending', 'succeeded', 'canceled', 'failed'])->default('pending');

            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('shop_id');
            $table->index('payment_id');
            $table->index('status');
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
