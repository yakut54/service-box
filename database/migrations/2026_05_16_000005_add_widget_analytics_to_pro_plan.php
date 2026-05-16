<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE plan_pricing
            SET features = features || '[\"widget_analytics\"]'::jsonb
            WHERE plan = 'pro'
              AND NOT (features @> '[\"widget_analytics\"]'::jsonb)
        ");
    }

    public function down(): void
    {
        DB::statement("
            UPDATE plan_pricing
            SET features = features - 'widget_analytics'
            WHERE plan = 'pro'
        ");
    }
};
