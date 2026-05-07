<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_pricing', function (Blueprint $table) {
            $table->integer('max_orders_per_month')->nullable()->after('price_kopecks');
            $table->integer('max_masters')->nullable()->after('max_orders_per_month');
            $table->jsonb('features')->default('[]')->after('max_masters');
        });

        DB::table('plan_pricing')->where('plan', 'micro')->update([
            'max_orders_per_month' => 100,
            'max_masters'          => 1,
            'features'             => json_encode([
                'До 100 заказов в месяц',
                '1 мастер',
                'Базовая аналитика',
                'Email уведомления',
            ]),
        ]);

        DB::table('plan_pricing')->where('plan', 'start')->update([
            'max_orders_per_month' => 1000,
            'max_masters'          => 3,
            'features'             => json_encode([
                'До 1 000 заказов в месяц',
                'До 3 мастеров',
                'Telegram уведомления',
                'Платёжные провайдеры',
                'Базовая аналитика',
            ]),
        ]);

        DB::table('plan_pricing')->where('plan', 'business')->update([
            'max_orders_per_month' => null,
            'max_masters'          => null,
            'features'             => json_encode([
                'Неограниченные заказы',
                'Неограниченное кол-во мастеров',
                'Приоритетная поддержка',
                'Кастомный виджет',
                'Экспорт данных',
                'Расширенная аналитика',
            ]),
        ]);

        DB::table('plan_pricing')->where('plan', 'pro')->update([
            'max_orders_per_month' => null,
            'max_masters'          => null,
            'features'             => json_encode([
                'Всё из Business',
                'API доступ',
                'White label',
                'Персональный менеджер',
                'Кастомная интеграция',
            ]),
        ]);
    }

    public function down(): void
    {
        Schema::table('plan_pricing', function (Blueprint $table) {
            $table->dropColumn(['max_orders_per_month', 'max_masters', 'features']);
        });
    }
};
