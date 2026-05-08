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
                WHERE table_schema = ? AND table_name = 'bookings' AND column_name = 'consent_offer_accepted'
            ", [$s]);

            if (!$exists) {
                DB::statement("ALTER TABLE {$s}.bookings
                    ADD COLUMN consent_offer_accepted BOOLEAN NOT NULL DEFAULT FALSE,
                    ADD COLUMN consent_privacy_accepted BOOLEAN NOT NULL DEFAULT FALSE,
                    ADD COLUMN consent_accepted_at TIMESTAMP,
                    ADD COLUMN consent_ip VARCHAR(45),
                    ADD COLUMN consent_ua VARCHAR(500)
                ");
            }
        }
    }

    public function down(): void {}
};
