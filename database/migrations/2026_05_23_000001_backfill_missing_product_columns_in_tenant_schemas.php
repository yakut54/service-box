<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $schemas = DB::select("
            SELECT schema_name
            FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $row) {
            $s = $row->schema_name;

            // Принимаем только схемы вида shop_<строчные буквы и цифры>
            if (!preg_match('/^shop_[a-z0-9]+$/', $s)) {
                Log::warning("backfill_product_columns: skipping suspicious schema name: {$s}");
                continue;
            }

            // products_physical
            DB::statement("ALTER TABLE \"{$s}\".products_physical ADD COLUMN IF NOT EXISTS color      TEXT");
            DB::statement("ALTER TABLE \"{$s}\".products_physical ADD COLUMN IF NOT EXISTS brand      TEXT");
            DB::statement("ALTER TABLE \"{$s}\".products_physical ADD COLUMN IF NOT EXISTS material   TEXT");
            DB::statement("ALTER TABLE \"{$s}\".products_physical ADD COLUMN IF NOT EXISTS dimensions TEXT");

            // products_digital
            DB::statement("ALTER TABLE \"{$s}\".products_digital ADD COLUMN IF NOT EXISTS file_size_mb NUMERIC(10,2)");
        }
    }

    public function down(): void
    {
        // Колонки оставляем — откат опасен потерей данных
    }
};
