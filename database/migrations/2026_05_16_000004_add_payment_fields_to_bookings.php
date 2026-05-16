<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schemas = DB::select("
            SELECT schema_name FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $row) {
            $s = $row->schema_name;

            $exists = DB::selectOne("
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = ? AND table_name = 'bookings' AND column_name = 'payment_url'
            ", [$s]);

            if (!$exists) {
                DB::statement("ALTER TABLE {$s}.bookings
                    ADD COLUMN payment_url VARCHAR(1024) NULL,
                    ADD COLUMN paid_at TIMESTAMPTZ NULL
                ");
            }
        }
    }

    public function down(): void {}
};
