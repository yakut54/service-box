<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE shops ADD COLUMN IF NOT EXISTS hide_customer_phone BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shops DROP COLUMN IF EXISTS hide_customer_phone');
    }
};
