<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schemas = DB::table('shops')->pluck('schema_name');
        foreach ($schemas as $schema) {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS reviews_customer_product_unique ON \"{$schema}\".reviews (customer_id, product_id)");
        }
    }

    public function down(): void
    {
        $schemas = DB::table('shops')->pluck('schema_name');
        foreach ($schemas as $schema) {
            DB::statement("DROP INDEX IF EXISTS \"{$schema}\".reviews_customer_product_unique");
        }
    }
};
