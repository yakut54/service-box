<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('plan_pricing')->where('plan', 'pro')->first();
        if (!$row) return;

        $features = json_decode($row->features, true) ?? [];
        if (!in_array('api_access', $features)) {
            $features[] = 'api_access';
            DB::table('plan_pricing')->where('plan', 'pro')->update([
                'features' => json_encode($features),
            ]);
        }
    }

    public function down(): void
    {
        $row = DB::table('plan_pricing')->where('plan', 'pro')->first();
        if (!$row) return;

        $features = array_values(array_filter(
            json_decode($row->features, true) ?? [],
            fn($f) => $f !== 'api_access'
        ));

        DB::table('plan_pricing')->where('plan', 'pro')->update([
            'features' => json_encode($features),
        ]);
    }
};
