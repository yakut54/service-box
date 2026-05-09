<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->bigInteger('max_chat_id')->nullable()->after('telegram_bot_connected');
            $table->boolean('max_bot_connected')->default(false)->after('max_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['max_chat_id', 'max_bot_connected']);
        });
    }
};
