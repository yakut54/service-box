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
                WHERE table_schema = ? AND table_name = 'products_digital' AND column_name = 'file_format'
            ", [$s]);

            if (!$exists) {
                DB::statement("ALTER TABLE {$s}.products_digital ADD COLUMN file_format TEXT");
            }
        }

        // Обновляем моковые данные для shop_omtgfybbrig5
        $schema = 'shop_omtgfybbrig5';
        $products = DB::table("{$schema}.products")
            ->where('type', 'digital')
            ->orderBy('created_at')
            ->pluck('id')
            ->values();

        if ($products->count() >= 1) {
            DB::table("{$schema}.products_digital")
                ->where('product_id', $products[0])
                ->update([
                    'delivery_type'   => 'download',
                    'download_url'    => 'https://yakut54.ru/storage/digital/hair-care-guide.pdf',
                    'access_days'     => 365,
                    'file_size_bytes' => 3 * 1024 * 1024,
                    'file_format'     => 'PDF',
                ]);
        }

        if ($products->count() >= 2) {
            DB::table("{$schema}.products_digital")
                ->where('product_id', $products[1])
                ->update([
                    'delivery_type'   => 'link',
                    'download_url'    => 'https://yakut54.ru/course/coloring-basics',
                    'access_days'     => 90,
                    'file_size_bytes' => null,
                    'file_format'     => null,
                ]);
        }

        if ($products->count() >= 3) {
            DB::table("{$schema}.products_digital")
                ->where('product_id', $products[2])
                ->update([
                    'delivery_type'   => 'code',
                    'download_url'    => 'SALON-XXXX-YYYY',
                    'access_days'     => null,
                    'file_size_bytes' => null,
                    'file_format'     => null,
                ]);
        }
    }

    public function down(): void {}
};
