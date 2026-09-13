<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_staff', function ($table) {
            if (!Schema::hasColumn('shop_staff', 'category_ids')) {
                $table->jsonb('category_ids')->nullable()->after('master_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_staff', function ($table) {
            $table->dropColumn('category_ids');
        });
    }
};
