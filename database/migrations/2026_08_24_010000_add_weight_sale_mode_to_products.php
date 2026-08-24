<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schemas = DB::table('shops')->pluck('schema_name');
        foreach ($schemas as $schema) {
            DB::statement("ALTER TABLE \"{$schema}\".products_physical ADD COLUMN IF NOT EXISTS sale_mode TEXT NOT NULL DEFAULT 'piece' CHECK (sale_mode IN ('piece', 'weight_fixed', 'weight_variable'))");
            DB::statement("ALTER TABLE \"{$schema}\".products_physical ADD COLUMN IF NOT EXISTS weight_step_grams INTEGER DEFAULT 100");
            DB::statement("ALTER TABLE \"{$schema}\".products_physical ADD COLUMN IF NOT EXISTS weight_min_grams INTEGER DEFAULT 100");
            DB::statement("ALTER TABLE \"{$schema}\".products_physical ADD COLUMN IF NOT EXISTS weight_max_grams INTEGER DEFAULT 5000");
            DB::statement("ALTER TABLE \"{$schema}\".order_items ADD COLUMN IF NOT EXISTS weight_grams INTEGER");
        }
    }

    public function down(): void
    {
        $schemas = DB::table('shops')->pluck('schema_name');
        foreach ($schemas as $schema) {
            DB::statement("ALTER TABLE \"{$schema}\".products_physical DROP COLUMN IF EXISTS sale_mode");
            DB::statement("ALTER TABLE \"{$schema}\".products_physical DROP COLUMN IF EXISTS weight_step_grams");
            DB::statement("ALTER TABLE \"{$schema}\".products_physical DROP COLUMN IF EXISTS weight_min_grams");
            DB::statement("ALTER TABLE \"{$schema}\".products_physical DROP COLUMN IF EXISTS weight_max_grams");
            DB::statement("ALTER TABLE \"{$schema}\".order_items DROP COLUMN IF EXISTS weight_grams");
        }
    }
};
