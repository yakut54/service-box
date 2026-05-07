<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Micro — полноценный рабочий план, лимиты говорят сами за себя
        DB::table('plan_pricing')->where('plan', 'micro')->update([
            'features' => json_encode([]),
        ]);

        // Start — реальные гейты сверх Micro
        DB::table('plan_pricing')->where('plan', 'start')->update([
            'features' => json_encode([
                'Telegram-уведомления',
                'Промокоды и скидки',
            ]),
        ]);

        // Business — инструменты масштаба
        DB::table('plan_pricing')->where('plan', 'business')->update([
            'features' => json_encode([
                'Экспорт данных CSV',
                'Кастомный виджет',
                'Аналитика за 3 месяца',
            ]),
        ]);

        // Pro — enterprise
        DB::table('plan_pricing')->where('plan', 'pro')->update([
            'features' => json_encode([
                'White label',
                'API доступ',
                'Персональный менеджер',
            ]),
        ]);
    }

    public function down(): void
    {
        DB::table('plan_pricing')->where('plan', 'micro')->update([
            'features' => json_encode([
                'До 100 заказов в месяц',
                '1 мастер',
                'Базовая аналитика',
                'Email уведомления',
            ]),
        ]);

        DB::table('plan_pricing')->where('plan', 'start')->update([
            'features' => json_encode([
                'До 1 000 заказов в месяц',
                'До 3 мастеров',
                'Telegram уведомления',
                'Платёжные провайдеры',
                'Базовая аналитика',
            ]),
        ]);

        DB::table('plan_pricing')->where('plan', 'business')->update([
            'features' => json_encode([
                'Неограниченные заказы',
                'Неограниченное кол-во мастеров',
                'Приоритетная поддержка',
                'Кастомный виджет',
                'Экспорт данных',
                'Расширенная аналитика',
            ]),
        ]);

        DB::table('plan_pricing')->where('plan', 'pro')->update([
            'features' => json_encode([
                'Всё из Business',
                'API доступ',
                'White label',
                'Персональный менеджер',
                'Кастомная интеграция',
            ]),
        ]);
    }
};
