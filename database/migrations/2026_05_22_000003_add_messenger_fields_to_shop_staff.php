<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_staff', function (Blueprint $table) {
            $table->bigInteger('telegram_chat_id')->nullable()->after('master_id');
            $table->bigInteger('max_user_id')->nullable()->after('telegram_chat_id');
            $table->string('messenger_link_token', 64)->nullable()->index()->after('max_user_id');
            $table->timestamp('messenger_link_token_expires_at')->nullable()->after('messenger_link_token');
        });
    }

    public function down(): void
    {
        Schema::table('shop_staff', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_id', 'max_user_id', 'messenger_link_token', 'messenger_link_token_expires_at']);
        });
    }
};
