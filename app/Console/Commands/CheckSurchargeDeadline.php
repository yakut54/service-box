<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\MaxService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Доплата за перевзвешенный заказ (см. OrderReweighService) не подтверждена за
 * 3 часа — переводим заказ в needs_attention и сообщаем владельцу. Тот же
 * паттерн, что SendBookingReminders: сырой SQL с явной схемой вместо
 * TenantService::inContext — воркер/крон вне тенантного контекста запроса.
 */
class CheckSurchargeDeadline extends Command
{
    protected $signature = 'orders:check-surcharge-deadline';
    protected $description = 'Переводит заказы с просроченной доплатой за перевзвешивание в needs_attention';

    public function handle(): int
    {
        $shops = Shop::all();

        foreach ($shops as $shop) {
            $this->processShop($shop);
        }

        return self::SUCCESS;
    }

    private function processShop(Shop $shop): void
    {
        $s = $shop->schema_name;

        $orders = DB::select("
            SELECT id, customer_name, customer_phone, surcharge_amount, status
            FROM {$s}.orders
            WHERE surcharge_status = 'pending'
              AND surcharge_deadline_at < NOW()
        ");

        foreach ($orders as $order) {
            try {
                // pick_note проставляем сами — автоматика, человека, который
                // мог бы написать причину, тут нет, а needs_attention без
                // заметки оставляет следующего сотрудника без контекста (тот
                // же принцип, что у release()/reportProblem(), см. PLAN.md
                // 2026-10-18).
                DB::statement(
                    "UPDATE {$s}.orders SET status = 'needs_attention', surcharge_status = 'expired',
                        pick_note = 'Доплата за перевес не подтверждена вовремя — дедлайн истёк',
                        pick_note_at = COALESCE(pick_note_at, NOW())
                     WHERE id = ?",
                    [$order->id]
                );
                // Свежий needs_attention — все сотрудники должны снова
                // увидеть заказ непрочитанным (см. order_seen, OrderController).
                DB::statement("DELETE FROM {$s}.order_seen WHERE order_id = ?", [$order->id]);

                // "История заказа" иначе осталась бы пустой для этого перехода
                // (живой разбор 2026-10-19) — у остальных путей в needs_attention
                // (release()/reportProblem()) запись пишет OrderActivityLog::
                // record(), но он принимает Eloquent-модель, а этот крон
                // намеренно работает сырым SQL вне тенантного контекста (см.
                // докблок класса) — здесь тот же формат, что у updateStatus()
                // (action=status_changed, from/to/note), просто своей вставкой.
                DB::statement(
                    "INSERT INTO {$s}.order_activity (order_id, action, from_status, to_status, note, actor_user_id, actor_name, actor_role)
                     VALUES (?, 'status_changed', ?, 'needs_attention', ?, NULL, 'Система', 'system')",
                    [$order->id, $order->status, 'Доплата за перевес не подтверждена вовремя — дедлайн истёк']
                );

                if ($shop->telegram_bot_connected) {
                    try { TelegramService::notifyOwnerSurchargeExpired($shop, $order); } catch (\Throwable) {}
                }
                if ($shop->max_bot_connected) {
                    try { MaxService::notifyOwnerSurchargeExpired($shop, $order); } catch (\Throwable) {}
                }

                \App\Events\OrdersUpdated::dispatch($shop->id);

                Log::info('[SurchargeDeadline] order moved to needs_attention', [
                    'shop' => $shop->id,
                    'order' => $order->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('[SurchargeDeadline] FAILED', [
                    'shop' => $shop->id,
                    'order' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
