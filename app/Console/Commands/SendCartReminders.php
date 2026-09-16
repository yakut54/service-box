<?php

namespace App\Console\Commands;

use App\Models\CartActivity;
use App\Models\Customer;
use App\Models\Shop;
use App\Services\Notifier;
use App\Services\TenantService;
use App\Support\PluralRu;
use App\Support\PushMessage;
use App\Support\QuietHours;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Напоминание о брошенной корзине (PLAN.md, Шаг 8). Первое напоминание — через
 * 2ч после последнего реального изменения непустой корзины без заказа,
 * повтор — через 24ч, если так и не оформили, дальше — тишина, пока корзина
 * снова не изменится или не станет заказом (см. CartActivityController,
 * OrderController::store).
 *
 * Отправляет НАПРЯМУЮ, без джобы в очередь — команда и так крутится в
 * контейнере scheduler, а не в HTTP-запросе (правило «только в очередь» из
 * PLAN.md — про HTTP-запросы; тот же приём уже у SendBookingReminders).
 */
class SendCartReminders extends Command
{
    protected $signature   = 'cart:send-reminders';
    protected $description = 'Push reminders for non-empty carts abandoned for 2h (repeat once after 24h)';

    public function handle(): int
    {
        foreach (Shop::all() as $shop) {
            if (QuietHours::isNight($shop)) {
                continue;
            }

            $this->processShop($shop);
        }

        return self::SUCCESS;
    }

    private function processShop(Shop $shop): void
    {
        TenantService::inContext($shop, function () use ($shop) {
            $rows = CartActivity::where('items_count', '>', 0)
                ->where(function ($q) {
                    $q->where(fn ($q2) => $q2->whereNull('first_reminded_at')
                                              ->where('updated_at', '<=', now()->subHours(2)))
                      ->orWhere(fn ($q2) => $q2->whereNotNull('first_reminded_at')
                                                ->whereNull('second_reminded_at')
                                                ->where('first_reminded_at', '<=', now()->subHours(24)));
                })
                ->get();

            foreach ($rows as $row) {
                $this->remind($shop, $row);
            }
        });
    }

    private function remind(Shop $shop, CartActivity $row): void
    {
        $isSecond = $row->first_reminded_at !== null;
        $n = $row->items_count;

        $customer = Customer::find($row->customer_id);
        if ($customer) {
            try {
                Notifier::toCustomer(
                    $shop,
                    $customer,
                    Notifier::TIER_BEHAVIORAL,
                    new PushMessage(
                        title: $isSecond ? 'Товары всё ещё ждут в корзине' : 'Вы забыли товары в корзине',
                        body: 'В корзине ' . $n . ' ' . PluralRu::ru($n, 'товар', 'товара', 'товаров') . ' — оформите заказ',
                        data: ['type' => 'cart_reminder'],
                        channelId: 'promo',
                    ),
                    entityType: 'cart_reminder',
                    entityId: (string) $row->customer_id,
                    fallbackText: 'Вы забыли товары в корзине — оформите заказ',
                );
            } catch (\Throwable $e) {
                Log::error('[CartReminders] send failed', [
                    'shop'     => $shop->id,
                    'customer' => $row->customer_id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // Штампуем ВСЕГДА, независимо от того, доставился ли push — иначе
        // байер с выключенными «поведенческими» уведомлениями (или внутри
        // тихого окна после транзакционного пуша, см. Notifier::allowed)
        // будет попадать в выборку каждые 15 минут бесконечно, и напоминание
        // в итоге прилетит когда попало вместо ровно 2ч/24ч. «Попытались» =
        // стадия пройдена — тот же приём, что у NotifyBackInStock::handle()
        // (подписка удаляется независимо от результата отправки).
        $row->timestamps = false; // не сдвигаем updated_at — это часы заброшенности, не факт записи в БД
        $row->update([$isSecond ? 'second_reminded_at' : 'first_reminded_at' => now()]);
    }
}
