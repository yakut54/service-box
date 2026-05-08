<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schema = 'shop_omtgfybbrig5';

        $products = DB::table("{$schema}.products")
            ->where('type', 'digital')
            ->orderBy('created_at')
            ->pluck('id')
            ->values();

        if ($products->count() < 3) {
            return;
        }

        // Первый — Скачивание (PDF-гайд)
        DB::table("{$schema}.products_digital")
            ->where('product_id', $products[0])
            ->update([
                'delivery_type'   => 'download',
                'download_url'    => 'https://yakut54.ru/storage/digital/hair-care-guide.pdf',
                'access_days'     => 365,
                'file_size_bytes' => 3 * 1024 * 1024,
            ]);

        // Второй — Ссылка (онлайн-курс)
        DB::table("{$schema}.products_digital")
            ->where('product_id', $products[1])
            ->update([
                'delivery_type'   => 'link',
                'download_url'    => 'https://yakut54.ru/course/coloring-basics',
                'access_days'     => 90,
                'file_size_bytes' => null,
            ]);

        // Третий — Код активации
        DB::table("{$schema}.products_digital")
            ->where('product_id', $products[2])
            ->update([
                'delivery_type'   => 'code',
                'download_url'    => null,
                'access_days'     => null,
                'file_size_bytes' => null,
            ]);
    }

    public function down(): void {}
};
