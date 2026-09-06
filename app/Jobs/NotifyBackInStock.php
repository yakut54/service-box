<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockSubscription;
use App\Services\Notifier;
use App\Services\TenantService;
use App\Support\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * «Товар снова в наличии» — покупателям, подписавшимся через
 * StockSubscriptionController. Диспатчится, когда остаток товара/варианта
 * переходит из 0 в положительный (ProductController::update,
 * PhysicalStockService::release). Подписка одноразовая — после уведомления
 * строка удаляется. Tier 2 (поведенческий): уважает настройки байера и кап
 * (см. Notifier).
 */
class NotifyBackInStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly string $shopId,
        private readonly string $productId,
        private readonly ?string $variantId,
    ) {}

    /** Собрать в текущем тенантном контексте. */
    public static function dispatchFor(string $productId, ?string $variantId): void
    {
        $shopId = TenantService::getCurrentShopId();
        if ($shopId) {
            self::dispatch($shopId, $productId, $variantId);
        }
    }

    public function handle(): void
    {
        $shop = Shop::find($this->shopId);
        if (!$shop) {
            return;
        }

        TenantService::inContext($shop, function () use ($shop) {
            $product = Product::find($this->productId);
            if (!$product) {
                return;
            }

            $subs = StockSubscription::where('product_id', $this->productId)
                ->when($this->variantId === null,
                    fn ($q) => $q->whereNull('variant_id'),
                    fn ($q) => $q->where('variant_id', $this->variantId),
                )
                ->whereNull('notified_at')
                ->get();

            if ($subs->isEmpty()) {
                return;
            }

            $comboLabel = null;
            if ($this->variantId !== null) {
                $comboLabel = ProductVariant::find($this->variantId)?->shortLabel();
            }
            $title = $product->name . ($comboLabel ? " ({$comboLabel})" : '');

            foreach ($subs as $sub) {
                $customer = Customer::find($sub->customer_id);
                if ($customer !== null) {
                    Notifier::toCustomer(
                        $shop,
                        $customer,
                        Notifier::TIER_BEHAVIORAL,
                        new PushMessage(
                            title: $title,
                            body: 'Товар снова в наличии',
                            data: [
                                'type'       => 'back_in_stock',
                                'product_id' => (string) $this->productId,
                            ],
                            channelId: 'promo',
                        ),
                        entityType: 'back_in_stock',
                        entityId: (string) $this->productId,
                        fallbackText: "«{$title}» снова в наличии",
                    );
                }
                $sub->delete();
            }
        });
    }
}
