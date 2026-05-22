<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_staff', function (Blueprint $table) {
            // UUID мастера в tenant-схеме (без FK — application-level link)
            $table->uuid('master_id')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('shop_staff', function (Blueprint $table) {
            $table->dropColumn('master_id');
        });
    }
};
