<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Снос всей системы тарифов/подписок — переход на плоскую комиссию 20%
 * (см. PLAN.md). subscriptions — проверено, мёртвый код (нигде не читается
 * и не пишется), остальное — реально использовалось до сегодняшнего дня.
 */
return new class extends Migration
{
    public function up(): void
    {
        // telegram_messages.type — убрать значения, которых больше не будет
        DB::statement('ALTER TABLE public.telegram_messages DROP CONSTRAINT IF EXISTS telegram_messages_type_check');
        DB::statement("
            ALTER TABLE public.telegram_messages
            ADD CONSTRAINT telegram_messages_type_check
            CHECK (type::text = ANY (ARRAY[
                'order_notification', 'booking_notification', 'booking_reminder',
                'payment_success', 'system_notification'
            ]::text[]))
        ");

        DB::statement('ALTER TABLE public.shops DROP CONSTRAINT IF EXISTS shops_subscription_plan_check');
        DB::statement('ALTER TABLE public.shops DROP COLUMN IF EXISTS subscription_plan');
        DB::statement('ALTER TABLE public.shops DROP COLUMN IF EXISTS subscription_expires_at');

        DB::statement('DROP TABLE IF EXISTS public.subscription_payments');
        DB::statement('DROP TABLE IF EXISTS public.subscriptions');
        DB::statement('DROP TABLE IF EXISTS public.plan_pricing');
    }

    public function down(): void
    {
        // Тарифы больше не возвращаем — если понадобится откат, поднимать
        // из бэкапа, а не пересоздавать пустые таблицы вслепую.
    }
};
