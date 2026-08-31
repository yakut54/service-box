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
            SELECT id, customer_name, customer_phone, surcharge_amount
            FROM {$s}.orders
            WHERE surcharge_status = 'pending'
              AND surcharge_deadline_at < NOW()
        ");

        foreach ($orders as $order) {
            try {
                DB::statement(
                    "UPDATE {$s}.orders SET status = 'needs_attention', surcharge_status = 'expired' WHERE id = ?",
                    [$order->id]
                );

                if ($shop->telegram_bot_connected) {
                    try { TelegramService::notifyOwnerSurchargeExpired($shop, $order); } catch (\Throwable) {}
                }
                if ($shop->max_bot_connected) {
                    try { MaxService::notifyOwnerSurchargeExpired($shop, $order); } catch (\Throwable) {}
                }

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
