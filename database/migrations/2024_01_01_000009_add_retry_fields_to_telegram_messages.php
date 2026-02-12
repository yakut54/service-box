<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_messages', function (Blueprint $table) {
            $table->integer('retry_count')->default(0)->after('status');
            $table->timestamp('last_retry_at')->nullable()->after('retry_count');

            $table->index(['status', 'retry_count', 'last_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::table('telegram_messages', function (Blueprint $table) {
            $table->dropIndex(['status', 'retry_count', 'last_retry_at']);
            $table->dropColumn(['retry_count', 'last_retry_at']);
        });
    }
};
