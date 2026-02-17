<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('work_start', 5)->default('09:00')->after('widget_config');
            $table->string('work_end', 5)->default('20:00')->after('work_start');
            $table->integer('slot_duration')->default(30)->after('work_end'); // minutes
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['work_start', 'work_end', 'slot_duration']);
        });
    }
};
