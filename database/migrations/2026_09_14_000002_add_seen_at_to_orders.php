<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schemas = DB::table('shops')->pluck('schema_name');
        foreach ($schemas as $schema) {
            DB::statement("ALTER TABLE \"{$schema}\".orders ADD COLUMN IF NOT EXISTS seen_at TIMESTAMPTZ");
            DB::statement("ALTER TABLE \"{$schema}\".orders ADD COLUMN IF NOT EXISTS pick_note_at TIMESTAMPTZ");
            DB::statement("ALTER TABLE \"{$schema}\".orders ADD COLUMN IF NOT EXISTS pick_note_edited_at TIMESTAMPTZ");
        }

        // Заменено per-заказным seen_at — заведено минуту назад, использовать
        // не успели.
        DB::statement('ALTER TABLE public.shops DROP COLUMN IF EXISTS orders_last_seen_at');
    }

    public function down(): void
    {
        $schemas = DB::table('shops')->pluck('schema_name');
        foreach ($schemas as $schema) {
            DB::statement("ALTER TABLE \"{$schema}\".orders DROP COLUMN IF EXISTS seen_at");
            DB::statement("ALTER TABLE \"{$schema}\".orders DROP COLUMN IF EXISTS pick_note_at");
            DB::statement("ALTER TABLE \"{$schema}\".orders DROP COLUMN IF EXISTS pick_note_edited_at");
        }

        DB::statement('ALTER TABLE public.shops ADD COLUMN IF NOT EXISTS orders_last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE');
    }
};
