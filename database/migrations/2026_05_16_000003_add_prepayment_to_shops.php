<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('prepayment_enabled')->default(false)->after('min_booking_notice');
            $table->integer('prepayment_amount')->default(0)->after('prepayment_enabled'); // 0 = full price
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['prepayment_enabled', 'prepayment_amount']);
        });
    }
};
