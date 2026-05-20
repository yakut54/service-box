<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_staff', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->uuid('user_id')->nullable();
            $table->string('role', 20)->default('admin');
            $table->string('invite_email')->nullable();
            $table->string('invite_token', 64)->nullable()->unique();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->unique(['shop_id', 'user_id']);
            $table->index('invite_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_staff');
    }
};
