<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->uuid('shop_id');
            $table->timestamp('expires_at');
            $table->boolean('used')->default(false);
            $table->timestamps();

            $table->foreign('shop_id')
                  ->references('id')
                  ->on('shops')
                  ->onDelete('cascade');

            $table->index(['code', 'used']);
            $table->index('shop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_codes');
    }
};
