<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Shop;
use App\Services\TenantService;
use App\Services\YooKassaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Подстраховка на случай, если вебхук ЮKassa не долетел (живым тестом
 * 2026-10-20 поймано: деньги списаны у ЮKassa — status=succeeded — а к нам
 * уведомление не пришло вообще, ни одной строки в логе, заказ навсегда
 * остался бы «Ожидает оплаты»). Вебхуки в принципе не 100%-надёжны у любого
 * платёжного провайдера — это не баг в нашем коде, а причина держать
 * периодическую сверку. Смотрим только последние 48 часов — старые
 * брошенные холды ЮKassa сама отменяет по тайм-ауту, сверять их нет смысла.
 */
class ReconcileYooKassaPayments extends Command
{
    protected $signature   = 'payments:reconcile-yookassa';
    protected $description = 'Сверяет с ЮKassa заказы с созданным платежом, которые не подтвердились вебхуком';

    public function handle(): int
    {
        $shops = Shop::whereNotNull('yookassa_shop_id')->whereNotNull('yookassa_secret_key')->get();

        foreach ($shops as $shop) {
            $this->processShop($shop);
        }

        return self::SUCCESS;
    }

    private function processShop(Shop $shop): void
    {
        TenantService::setContext($shop);

        $orders = Order::whereNotNull('payment_id')
            ->whereNull('paid_at')
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', now()->subHours(48))
            ->get();

        if ($orders->isEmpty()) {
            return;
        }

        $yooKassa = new YooKassaService($shop->yookassa_shop_id, $shop->yookassa_secret_key);

        foreach ($orders as $order) {
            try {
                $info = $yooKassa->getPaymentStatus($order->payment_id);

                // waiting_for_capture/pending — платёж ещё законно не завершён
                // (холд «по весу — перевзвешивание» до сборки, см.
                // OrderReweighService), это не потеря вебхука, пропускаем.
                if ($info['status'] !== 'succeeded') {
                    continue;
                }

                $order->markAsPaid($order->payment_id);

                Log::warning('[ReconcileYooKassa] webhook was missed, payment reconciled manually', [
                    'shop_id'    => $shop->id,
                    'order_id'   => $order->id,
                    'payment_id' => $order->payment_id,
                ]);
            } catch (\Throwable $e) {
                Log::error('[ReconcileYooKassa] FAILED', [
                    'shop_id'  => $shop->id,
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }
}
