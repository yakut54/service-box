<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_pricing', function (Blueprint $table) {
            $table->jsonb('capabilities')->default('[]')->after('features');
        });

        // Migrate technical keys out of features into capabilities
        $technical = ['api_access', 'staff_management', 'widget_analytics', 'widget_customization', 'custom_css', 'all_features'];

        $rows = DB::table('plan_pricing')->get();
        foreach ($rows as $row) {
            $features     = json_decode($row->features, true) ?? [];
            $caps         = array_values(array_filter($features, fn($f) => in_array($f, $technical)));
            $displayOnly  = array_values(array_filter($features, fn($f) => !in_array($f, $technical)));

            DB::table('plan_pricing')->where('plan', $row->plan)->update([
                'features'     => json_encode($displayOnly, JSON_UNESCAPED_UNICODE),
                'capabilities' => json_encode($caps,        JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        // Merge capabilities back into features
        $rows = DB::table('plan_pricing')->get();
        foreach ($rows as $row) {
            $features = json_decode($row->features,     true) ?? [];
            $caps     = json_decode($row->capabilities, true) ?? [];
            DB::table('plan_pricing')->where('plan', $row->plan)->update([
                'features' => json_encode(array_merge($features, $caps), JSON_UNESCAPED_UNICODE),
            ]);
        }

        Schema::table('plan_pricing', function (Blueprint $table) {
            $table->dropColumn('capabilities');
        });
    }
};
