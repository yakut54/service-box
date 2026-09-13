<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE public.shops ADD COLUMN IF NOT EXISTS orders_last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE public.shops DROP COLUMN IF EXISTS orders_last_seen_at');
    }
};
