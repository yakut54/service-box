<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('plan_pricing')->where('plan', 'micro')->update([
            'max_orders_per_month' => 100,
            'max_masters'          => 1,
        ]);

        DB::table('plan_pricing')->where('plan', 'start')->update([
            'max_orders_per_month' => 1000,
            'max_masters'          => 3,
        ]);

        DB::table('plan_pricing')->where('plan', 'business')->update([
            'max_orders_per_month' => null,
            'max_masters'          => null,
        ]);

        DB::table('plan_pricing')->where('plan', 'pro')->update([
            'max_orders_per_month' => null,
            'max_masters'          => null,
        ]);
    }

    public function down(): void {}
};
