<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['business', 'pro'] as $plan) {
            $row = DB::table('plan_pricing')->where('plan', $plan)->first();
            if (!$row) continue;

            $features = json_decode($row->features, true) ?? [];
            if (!in_array('staff_management', $features)) {
                $features[] = 'staff_management';
                DB::table('plan_pricing')->where('plan', $plan)->update([
                    'features' => json_encode($features),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['business', 'pro'] as $plan) {
            $row = DB::table('plan_pricing')->where('plan', $plan)->first();
            if (!$row) continue;

            $features = array_values(array_filter(
                json_decode($row->features, true) ?? [],
                fn($f) => $f !== 'staff_management'
            ));
            DB::table('plan_pricing')->where('plan', $plan)->update([
                'features' => json_encode($features),
            ]);
        }
    }
};
