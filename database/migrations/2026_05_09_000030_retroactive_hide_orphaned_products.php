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

            DB::statement("
                UPDATE \"{$s}\".products p
                SET is_active = FALSE, auto_hidden = TRUE
                WHERE p.is_active = TRUE
                  AND EXISTS (
                      SELECT 1 FROM \"{$s}\".master_services ms WHERE ms.product_id = p.id
                  )
                  AND NOT EXISTS (
                      SELECT 1
                      FROM \"{$s}\".master_services ms2
                      JOIN \"{$s}\".masters m ON m.id = ms2.master_id
                      WHERE ms2.product_id = p.id
                        AND m.is_active = TRUE
                  )
            ");
        }
    }

    public function down(): void {}
};
