<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schemas = DB::table('shops')->pluck('schema_name');
        foreach ($schemas as $schema) {
            DB::statement("ALTER TABLE \"{$schema}\".orders ADD COLUMN IF NOT EXISTS collector_id UUID");
            DB::statement("ALTER TABLE \"{$schema}\".orders ADD COLUMN IF NOT EXISTS collector_name TEXT");
            DB::statement("ALTER TABLE \"{$schema}\".orders ADD COLUMN IF NOT EXISTS picking_started_at TIMESTAMPTZ");
            DB::statement("ALTER TABLE \"{$schema}\".orders ADD COLUMN IF NOT EXISTS pick_note TEXT");
            DB::statement("ALTER TABLE \"{$schema}\".order_items ADD COLUMN IF NOT EXISTS picked_qty INTEGER");
        }
    }

    public function down(): void
    {
        $schemas = DB::table('shops')->pluck('schema_name');
        foreach ($schemas as $schema) {
            DB::statement("ALTER TABLE \"{$schema}\".orders DROP COLUMN IF EXISTS collector_id");
            DB::statement("ALTER TABLE \"{$schema}\".orders DROP COLUMN IF EXISTS collector_name");
            DB::statement("ALTER TABLE \"{$schema}\".orders DROP COLUMN IF EXISTS picking_started_at");
            DB::statement("ALTER TABLE \"{$schema}\".orders DROP COLUMN IF EXISTS pick_note");
            DB::statement("ALTER TABLE \"{$schema}\".order_items DROP COLUMN IF EXISTS picked_qty");
        }
    }
};
