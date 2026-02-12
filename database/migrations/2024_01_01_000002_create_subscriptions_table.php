<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->string('plan');
            $table->integer('amount_kopecks');
            $table->string('payment_id')->nullable();
            $table->string('payment_provider')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('shop_id')
                  ->references('id')
                  ->on('shops')
                  ->onDelete('cascade');

            $table->index('shop_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
