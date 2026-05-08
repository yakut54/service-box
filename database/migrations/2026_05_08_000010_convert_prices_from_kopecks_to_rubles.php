<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── Global tables ────────────────────────────────────────────
        DB::statement('UPDATE plan_pricing SET price_kopecks = ROUND(price_kopecks / 100)');
        DB::statement('UPDATE subscriptions SET amount_kopecks = ROUND(amount_kopecks / 100)');

        // ── Shop schemas ─────────────────────────────────────────────
        $schemas = DB::select("
            SELECT schema_name FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $row) {
            $s = $row->schema_name;

            DB::statement("UPDATE {$s}.products SET price = ROUND(price / 100)");
            DB::statement("UPDATE {$s}.products SET compare_price = ROUND(compare_price / 100) WHERE compare_price IS NOT NULL");
            DB::statement("UPDATE {$s}.orders SET total_price = ROUND(total_price / 100)");
            DB::statement("UPDATE {$s}.orders SET discount_amount = ROUND(discount_amount / 100)");
            DB::statement("UPDATE {$s}.order_items SET price = ROUND(price / 100)");

            // discounts
            if ($this->columnExists($s, 'discounts', 'value')) {
                DB::statement("UPDATE {$s}.discounts SET value = ROUND(value / 100) WHERE type = 'fixed'");
                DB::statement("UPDATE {$s}.discounts SET min_order_amount = ROUND(min_order_amount / 100)");
                DB::statement("UPDATE {$s}.discounts SET max_discount_amount = ROUND(max_discount_amount / 100) WHERE max_discount_amount IS NOT NULL");
            }

            // discount_uses
            if ($this->columnExists($s, 'discount_uses', 'discount_amount')) {
                DB::statement("UPDATE {$s}.discount_uses SET discount_amount = ROUND(discount_amount / 100)");
            }
        }
    }

    public function down(): void
    {
        // Irreversible
    }

    private function columnExists(string $schema, string $table, string $column): bool
    {
        $result = DB::selectOne("
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = ? AND table_name = ? AND column_name = ?
        ", [$schema, $table, $column]);

        return $result !== null;
    }
};
