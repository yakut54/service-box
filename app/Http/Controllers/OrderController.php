<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Services\DiscountService;
use App\Services\OrderReweighService;
use App\Services\TenantService;
use App\Support\CategoryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(private readonly DiscountService $discountService) {}

    /**
     * Админ, ограниченный владельцем набором категорий (см. CategoryAccess),
     * видит заказ целиком, если внутри есть хоть один товар из его категорий
     * — решение: не резать заказ на части и не прятать чужие строки внутри
     * него, иначе сумма в заказе разъехалась бы с тем, что показывает
     * аналитика того же заказа.
     */
    private function applyCategoryScope(\Illuminate\Database\Eloquent\Builder $query, Request $request): void
    {
        $allowed = CategoryAccess::expandedIds($request);
        if ($allowed !== null) {
            $query->whereHas('items.product', fn ($q) => $q->whereIn('category_id', $allowed));
        }
    }

    /** @param Order $order с загруженной связью items.product */
    private function orderInScope(Order $order, Request $request): bool
    {
        $allowed = CategoryAccess::expandedIds($request);
        if ($allowed === null) {
            return true;
        }

        return $order->items->contains(
            fn ($item) => $item->product && in_array($item->product->category_id, $allowed, true)
        );
    }

    /**
     * Ответ для роли collector — не отдаём Order как есть: сборщику не нужны
     * (и не должны быть видны) комиссия, статус выплаты, скидки, id/ссылки
     * платежей и email покупателя. Телефон уважает тот же флаг
     * shops.hide_customer_phone, что уже скрывает его от мастеров (см.
     * MasterBotService::buildPayload) — единая настройка «скрыть контакты
     * покупателя от персонала», не своя для каждой роли.
     * Единая точка сборки — используется в index/show/claim/pickItem/
     * updateStatus/submitItemWeight/reportProblem, чтобы ответы не разъезжались.
     */
    private function collectorPayload(Order $order, ?Shop $shop): array
    {
        $data = $order->toArray();

        unset(
            $data['commission_amount'],
            $data['payout_status'],
            $data['discount_id'],
            $data['discount_code'],
            $data['discount_amount'],
            $data['payment_id'],
            $data['payment_url'],
            $data['customer_email'],
            $data['consent_offer_accepted'],
            $data['consent_privacy_accepted'],
            $data['consent_accepted_at'],
            $data['consent_ip'],
            $data['consent_ua'],
            $data['surcharge_payment_id'],
            $data['surcharge_payment_url'],
        );

        if ($shop?->hide_customer_phone) {
            $data['customer_phone'] = null;
            if (isset($data['customer']) && is_array($data['customer'])) {
                unset($data['customer']['phone']);
            }
        }

        if (isset($data['customer']) && is_array($data['customer'])) {
            unset($data['customer']['email']);
        }

        return $data;
    }

    /**
     * Get list of orders
     *
     * Query params: status, customer_id, date_from, date_to, search
     * Роль collector: не история, а очередь на сборку — см. §Часть A плана.
     */
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $isCollector = $request->attributes->get('staff_role') === 'collector';

        // items.product.physical — сборщику (роль collector) нужен sale_mode и
        // вес каждой позиции, чтобы показать поле ввода факт. веса только для
        // weight_variable (см. CollectorOrderDetailView.vue).
        $query = Order::query()->with(['items.product.physical', 'customer']);

        if ($isCollector) {
            if ($request->input('scope') === 'done') {
                // «Собранные сегодня» — по таймзоне магазина, не сервера.
                $todayStart = now($shop->timezone ?? 'Europe/Moscow')->startOfDay();
                $query->whereIn('status', ['completed', 'cancelled'])
                      ->where('created_at', '>=', $todayStart);
            } else {
                $query->whereIn('status', ['pending', 'paid', 'processing', 'needs_attention']);
            }
        } else {
            if ($request->filled('status')) {
                $query->withStatus($request->status);
            }

            if ($request->filled('customer_id')) {
                $query->where('customer_id', $request->customer_id);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('customer_name', 'ILIKE', "%{$search}%")
                      ->orWhere('customer_phone', 'ILIKE', "%{$search}%")
                      ->orWhere('customer_email', 'ILIKE', "%{$search}%");
                });
            }
        }

        $this->applyCategoryScope($query, $request);

        // Сборщику — старые заказы первыми (FIFO, кто дольше ждёт), не
        // последние сверху, как у владельца в истории.
        $orders = $isCollector ? $query->oldest('created_at')->get() : $query->latest('created_at')->get();

        $data = $isCollector ? $orders->map(fn ($o) => $this->collectorPayload($o, $shop))->values() : $orders;

        return response()->json([
            'data' => $data,
            'count' => $orders->count(),
        ]);
    }

    /**
     * Store a new order
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $shop = $request->attributes->get('shop')
            ?? Shop::find(TenantService::getCurrentShopId());

        $customer = Customer::findOrCreateByPhone(
            $request->input('customer.phone'),
            [
                'name' => $request->input('customer.name'),
                'email' => $request->input('customer.email'),
            ]
        );

        [$order, $cartItems] = DB::transaction(function () use ($request, $customer) {
            $order = Order::create([
                'customer_id'             => $customer->id,
                'status'                  => 'pending',
                'customer_name'           => $request->input('customer.name'),
                'customer_email'          => $request->input('customer.email'),
                'customer_phone'          => Customer::normalizePhone($request->input('customer.phone')),
                'shipping_address'        => $request->shipping_address,
                'delivery_method'         => $request->delivery_method,
                'delivery_price'          => (int) ($request->delivery_price ?? 0),
                'notes'                   => $request->notes,
                'consent_offer_accepted'  => (bool) $request->input('consent_offer_accepted', false),
                'consent_privacy_accepted'=> (bool) $request->input('consent_privacy_accepted', false),
                'consent_accepted_at'     => now(),
                'consent_ip'              => $request->ip(),
                'consent_ua'              => substr($request->userAgent() ?? '', 0, 500),
            ]);

            $cartItems = [];

            foreach ($request->items as $item) {
                // lockForUpdate предотвращает overselling при параллельных заказах
                $product  = Product::with('physical')->lockForUpdate()->findOrFail($item['product_id']);
                $saleMode = $product->physical->sale_mode ?? 'piece';
                $weightGrams = null;
                $variant = null;
                $variantLabel = null;

                if ($saleMode === 'piece') {
                    if (!isset($item['quantity'])) {
                        abort(422, "Не указано количество для товара «{$product->name}»");
                    }
                    $quantity  = (int) $item['quantity'];

                    // Товар с вариантами (размер/цвет) — нужен конкретный вариант.
                    $hasVariants = $product->options()->exists();
                    if (!empty($item['variant_id'])) {
                        $variant = \App\Models\ProductVariant::where('product_id', $product->id)
                            ->whereKey($item['variant_id'])
                            ->lockForUpdate()
                            ->first();
                        if (!$variant || !$variant->is_active) {
                            abort(422, "Выбранный вариант товара «{$product->name}» недоступен");
                        }
                        $names = $product->options()->orderBy('position')->pluck('name')->all();
                        $variantLabel = $variant->fullLabel($names);
                    } elseif ($hasVariants) {
                        abort(422, "Для товара «{$product->name}» нужно выбрать вариант");
                    }

                    $itemPrice = $variant ? $variant->effectivePrice($product->price) : $product->price;

                    if ($product->type === 'physical' && $product->physical) {
                        \App\Services\PhysicalStockService::reserve($product, $quantity, null, $variant);
                    }
                } else {
                    // weight_fixed / weight_variable — см. PLAN.md, «Развесной товар».
                    // Остаток по весу списывается только для weight_fixed —
                    // weight_variable сам как фича ещё не построен (см.
                    // PhysicalStockService::reserve).
                    if (!isset($item['weight_grams'])) {
                        abort(422, "Не указан вес для товара «{$product->name}»");
                    }
                    $weightGrams = (int) $item['weight_grams'];
                    $min  = $product->physical->weight_min_grams ?? 100;
                    $max  = $product->physical->weight_max_grams ?? 5000;
                    $step = $product->physical->weight_step_grams ?? 100;

                    if ($weightGrams < $min || $weightGrams > $max) {
                        abort(422, "Вес товара «{$product->name}» должен быть от {$min} до {$max} г");
                    }
                    if (($weightGrams - $min) % $step !== 0) {
                        abort(422, "Вес товара «{$product->name}» должен быть кратен шагу {$step} г");
                    }

                    if ($product->type === 'physical' && $product->physical) {
                        \App\Services\PhysicalStockService::reserve($product, 1, $weightGrams);
                    }

                    $quantity = 1;
                    // price у продукта в весовом режиме — это цена за кг (в
                    // копейках); строка заказа хранит уже посчитанную
                    // абсолютную стоимость этой навески с quantity=1 — так
                    // формула price*quantity (скидки/комиссия/итог заказа)
                    // остаётся неизменной для обоих режимов продажи.
                    $itemPrice = (int) round($product->price * $weightGrams / 1000);
                }

                $order->items()->create([
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'variant_label' => $variantLabel,
                    'quantity' => $quantity,
                    'price' => $itemPrice,
                    'product_name' => $product->name,
                    'product_type' => $product->type,
                    'weight_grams' => $weightGrams,
                ]);

                $cartItems[] = [
                    'product_id'  => $product->id,
                    'category_id' => $product->category_id ?? null,
                    'quantity'    => $quantity,
                    'price'       => $itemPrice,
                ];
            }

            $order->calculateTotal();

            $minOrder = config('platform.min_order_amount_kopecks');
            if ($order->total_price < $minOrder) {
                abort(422, 'Минимальная сумма заказа — ' . number_format($minOrder / 100, 0, ',', ' ') . ' ₽');
            }

            return [$order, $cartItems];
        });

        // Apply discount: best amount wins between promo code and auto-apply
        $discount       = null;
        $discountAmount = 0;

        $promoDiscount = null;
        $promoAmount   = 0;

        if ($request->filled('discount_code')) {
            try {
                $result        = $this->discountService->validate(
                    $request->discount_code,
                    $order->total_price,
                    $request->input('customer.phone'),
                    $cartItems,
                );
                $promoDiscount = $result['discount'];
                $promoAmount   = $result['amount'];
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json([
                    'message' => $e->errors()['discount_code'][0] ?? 'Промокод недействителен',
                    'errors'  => $e->errors(),
                ], 422);
            }
        }

        $autoDiscount = $this->discountService->findAutoApply(
            $order->total_price,
            $request->input('customer.phone'),
            $cartItems,
        );
        $autoAmount = $autoDiscount
            ? $this->discountService->calculate($autoDiscount, $order->total_price, $cartItems)
            : 0;

        // Best discount wins; promo code wins on tie (user made an explicit choice)
        if ($promoDiscount && $promoAmount >= $autoAmount) {
            $discount       = $promoDiscount;
            $discountAmount = $promoAmount;
        } elseif ($autoDiscount && $autoAmount > $promoAmount) {
            $discount       = $autoDiscount;
            $discountAmount = $autoAmount;
        }

        if ($discount && $discountAmount > 0) {
            $order->update([
                'discount_id'     => $discount->id,
                'discount_code'   => $discount->code,
                'discount_amount' => $discountAmount,
                'total_price'     => max(0, $order->total_price - $discountAmount),
            ]);
            $this->discountService->recordUse($discount, $order);
        }

        // Add delivery cost after discount (discount applies to goods only)
        if ($order->delivery_price > 0) {
            $order->update(['total_price' => $order->total_price + $order->delivery_price]);
            $order->refresh();
        }

        $customer->updateStats();
        $order->load(['items.product.physical', 'customer']);

        // «Заказал сегодня» — гейт для промо-рассылок на сутки (см. Notifier).
        if ($shop) {
            \App\Services\Notifier::markOrdered($shop->id, $customer->id);
        }

        if ($shop) {
            try {
                \App\Services\MailService::notifyNewOrder($shop, $order);
            } catch (\Throwable) {}
            try {
                \App\Services\TelegramService::notifyNewOrder($shop, $order);
            } catch (\Throwable) {}
            try {
                \App\Services\MaxService::notifyNewOrder($shop, $order);
            } catch (\Throwable) {}

            // Открытая очередь сборщика/владельца подхватывает новый заказ
            // без перезагрузки страницы (см. App\Events\OrdersUpdated).
            \App\Events\OrdersUpdated::dispatch($shop->id);
        }

        // Заказ с товаром «по весу — перевзвешивание» может обернуться доплатой
        // после сборки (см. OrderReweighService) — сразу предлагаем покупателю
        // привязать Telegram/MAX, чтобы было куда её прислать. Для обычных
        // заказов не нужно — не спамим лишним предложением привязки.
        $hasWeightVariable = $order->items->contains(
            fn ($item) => $item->product?->physical?->sale_mode === 'weight_variable'
        );

        $telegramLink = null;
        $maxLink = null;
        $maxCode = null;

        if ($shop && $hasWeightVariable) {
            if ($shop->telegram_bot_connected) {
                try {
                    $telegramLink = \App\Services\TelegramService::generateCustomerLinkToken(
                        $shop,
                        $order->customer_phone
                    );
                } catch (\Throwable) {}
            }
            if ($shop->max_bot_connected && config('services.max.bot_username')) {
                try {
                    $maxCode = \App\Services\MaxService::generateCustomerCode($order->id);
                    $maxLink = 'https://max.ru/' . config('services.max.bot_username') . '?start=' . $maxCode;
                } catch (\Throwable) {}
            }
        }

        $orderData = $order->toArray();
        $orderData['telegram_link'] = $telegramLink;
        $orderData['max_link']      = $maxLink;
        $orderData['max_code']      = $maxCode;

        return response()->json([
            'message' => 'Заказ успешно создан',
            'data'    => $orderData,
        ], 201);
    }

    /**
     * Get single order
     */
    public function show(Request $request, string $order): JsonResponse
    {
        // .physical — раньше здесь грузился только items.product, из-за чего
        // экран сборщика не видел sale_mode и не показывал ввод веса для
        // weight_variable позиций при заходе напрямую на заказ (не из списка).
        $order = Order::with(['items.product.physical', 'customer'])->findOrFail($order);

        if (!$this->orderInScope($order, $request)) {
            abort(404);
        }

        $isCollector = $request->attributes->get('staff_role') === 'collector';

        return response()->json([
            'data' => $isCollector
                ? $this->collectorPayload($order, $request->attributes->get('shop'))
                : $order,
        ]);
    }

    /**
     * Update order status
     *
     * PATCH /api/admin/orders/{order}/status
     */
    public function updateStatus(Request $request, string $order): JsonResponse
    {
        $order = Order::with('items.product.physical')->findOrFail($order);

        if (!$this->orderInScope($order, $request)) {
            abort(404);
        }

        $isCollector = $request->attributes->get('staff_role') === 'collector';

        $request->validate([
            // 'paid' сюда сознательно не входит — этот статус проставляет
            // только вебхук ЮKassa (Order::markAsPaid), после реальной
            // оплаты через шлюз. Иначе шопер мог бы щёлкнуть «оплачено»
            // без единого рубля и не заплатить комиссию (см. PLAN.md).
            // Сборщик отдельно не может «отменить» — это решение владельца/
            // админа, у сборщика для проблемных заказов есть /problem.
            'status' => $isCollector ? 'required|in:processing,completed' : 'required|in:processing,completed,cancelled',
            'note'   => 'nullable|string|max:1000',
        ]);

        $newStatus = $request->status;

        if ($newStatus === 'completed' && !$order->paid_at) {
            return response()->json([
                'message' => 'Нельзя завершить неоплаченный заказ — оплата проходит только через ЮKassa',
            ], 422);
        }

        if ($newStatus === 'completed' && $order->surcharge_status === 'pending') {
            return response()->json([
                'message' => 'Нельзя завершить заказ — покупатель ещё не подтвердил доплату за перевзвешенный товар',
            ], 422);
        }

        // Сборщик завершает заказ только когда разобрал каждую позицию —
        // тапом (picked_qty) или взвешиванием (actual_weight_grams для
        // weight_variable). Гейт только для роли collector: владелец/админ
        // по-прежнему может завершить заказ напрямую из обычной админки, не
        // проходя чек-лист сборки (не у всех магазинов вообще есть сборщик).
        if ($newStatus === 'completed' && $isCollector) {
            $unresolved = $order->items->contains(function ($item) {
                return $item->product?->physical?->sale_mode === 'weight_variable'
                    ? $item->actual_weight_grams === null
                    : $item->picked_qty === null;
            });

            if ($unresolved) {
                return response()->json(['message' => 'Разберите все позиции заказа перед завершением'], 422);
            }

            $hasShortage = $order->items->contains(
                fn ($item) => $item->product?->physical?->sale_mode !== 'weight_variable'
                    && $item->picked_qty < $item->quantity
            );

            if ($hasShortage && !$request->filled('note')) {
                return response()->json(['message' => 'Укажите причину недобора перед завершением'], 422);
            }
        }

        $oldStatus = $order->status;

        $update = ['status' => $newStatus];
        if ($request->filled('note')) {
            $update['pick_note'] = $request->note;
        }
        $order->update($update);

        if ($newStatus !== $oldStatus) {
            \App\Jobs\SendOrderStatusPush::dispatchFor($order, $newStatus);
        }

        if ($newStatus === 'cancelled' && $oldStatus !== 'cancelled') {
            // Возвращаем товары на склад
            foreach ($order->items as $item) {
                \App\Services\PhysicalStockService::release($item);
            }

            // Комиссия возвращается вместе с заказом — без исключений
            if ($order->commission_amount > 0) {
                $order->update(['commission_amount' => 0]);
            }
        }

        $shop = $request->attributes->get('shop');
        if ($newStatus !== $oldStatus && $shop) {
            \App\Events\OrdersUpdated::dispatch($shop->id);
        }

        $order->load(['items.product.physical', 'customer']);

        return response()->json([
            'message' => 'Статус заказа обновлён',
            'data' => $isCollector ? $this->collectorPayload($order, $shop) : $order,
        ]);
    }

    /**
     * Сборщик (или владелец) вводит фактический вес одной weight_variable-
     * позиции. Если это была последняя невзвешенная позиция заказа —
     * OrderReweighService сразу считает итог и списывает/довзыскивает деньги.
     *
     * PATCH /api/admin/orders/{order}/items/{item}/weight
     */
    public function submitItemWeight(Request $request, string $order, string $item): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $request->validate([
            'actual_weight_grams' => 'required|integer|min:0',
        ]);

        $orderModel = Order::with('items.product')->findOrFail($order);

        if (!$this->orderInScope($orderModel, $request)) {
            abort(404);
        }

        $itemModel = OrderItem::where('order_id', $orderModel->id)
            ->with('product.physical')
            ->findOrFail($item);

        if ($itemModel->product?->physical?->sale_mode !== 'weight_variable') {
            return response()->json(['message' => 'Эта позиция не требует взвешивания'], 422);
        }

        if ($itemModel->actual_weight_grams !== null) {
            return response()->json(['message' => 'Вес по этой позиции уже подтверждён'], 422);
        }

        OrderReweighService::submitActualWeight($itemModel, (int) $request->actual_weight_grams, $shop);

        $orderModel->refresh()->load(['items.product.physical', 'customer']);

        $isCollector = $request->attributes->get('staff_role') === 'collector';

        return response()->json([
            'message' => 'Вес подтверждён',
            'data' => $isCollector ? $this->collectorPayload($orderModel, $shop) : $orderModel,
        ]);
    }

    /**
     * Сборщик берёт заказ в работу.
     *
     * Мягкий лок, не жёсткий: магазин маленький (1-3 сборщика), полная
     * блокировка чужого заказа только мешала бы, если один отвлёкся —
     * поэтому вместо запрета даём знать, кто уже собирает, и явную кнопку
     * «Всё равно взять» (?takeover=1) на фронте, а не тихий автозахват.
     *
     * PATCH /api/admin/orders/{order}/claim
     */
    public function claim(Request $request, string $order): JsonResponse
    {
        $orderModel = Order::with(['items.product.physical', 'customer'])->findOrFail($order);

        if (!$this->orderInScope($orderModel, $request)) {
            abort(404);
        }

        if (in_array($orderModel->status, ['completed', 'cancelled'], true)) {
            return response()->json(['message' => 'Этот заказ уже закрыт'], 422);
        }

        $staffId = $request->attributes->get('staff_id');

        if ($orderModel->collector_id && $orderModel->collector_id !== $staffId && !$request->boolean('takeover')) {
            return response()->json([
                'message' => "Заказ уже собирает {$orderModel->collector_name}",
                'collector_name' => $orderModel->collector_name,
            ], 409);
        }

        $orderModel->update([
            'collector_id' => $staffId,
            'collector_name' => $request->user()->name,
            'picking_started_at' => $orderModel->picking_started_at ?? now(),
            'status' => in_array($orderModel->status, ['pending', 'paid'], true) ? 'processing' : $orderModel->status,
        ]);

        $shop = $request->attributes->get('shop');
        \App\Events\OrdersUpdated::dispatch($shop->id);

        $orderModel->refresh()->load(['items.product.physical', 'customer']);

        return response()->json([
            'message' => 'Заказ взят в работу',
            'data' => $this->collectorPayload($orderModel, $shop),
        ]);
    }

    /**
     * Сборщик отмечает штучную позицию собранной (или снимает отметку).
     * Для weight_variable позиций признаком «собрано» остаётся
     * actual_weight_grams (см. submitItemWeight) — второй источник правды
     * не заводим.
     *
     * PATCH /api/admin/orders/{order}/items/{item}/pick
     */
    public function pickItem(Request $request, string $order, string $item): JsonResponse
    {
        $request->validate([
            'picked_qty' => 'nullable|integer|min:0',
        ]);

        $orderModel = Order::with(['items.product.physical', 'customer'])->findOrFail($order);

        if (!$this->orderInScope($orderModel, $request)) {
            abort(404);
        }

        if (in_array($orderModel->status, ['completed', 'cancelled'], true)) {
            return response()->json(['message' => 'Заказ уже закрыт'], 422);
        }

        $itemModel = OrderItem::where('order_id', $orderModel->id)
            ->with('product.physical')
            ->findOrFail($item);

        if ($itemModel->product?->physical?->sale_mode === 'weight_variable') {
            return response()->json(['message' => 'Эта позиция взвешивается, а не отмечается'], 422);
        }

        $pickedQty = $request->input('picked_qty');
        if ($pickedQty !== null && $pickedQty > $itemModel->quantity) {
            return response()->json(['message' => 'Нельзя собрать больше, чем заказано'], 422);
        }

        $itemModel->update(['picked_qty' => $pickedQty]);

        // Без OrdersUpdated здесь намеренно — это отметка на своём же
        // открытом экране, рассылать её самому себе незачем (см. план).

        $orderModel->refresh()->load(['items.product.physical', 'customer']);

        return response()->json([
            'message' => 'Отметка сохранена',
            'data' => $this->collectorPayload($orderModel, $request->attributes->get('shop')),
        ]);
    }

    /**
     * Сборщик сообщает о проблеме (брак, недобор, нашёл повреждённым и т.п.)
     * вместо отмены заказа — решение остаётся за владельцем/админом
     * (см. OrderDetailView.vue — там появится заметка и имя сборщика).
     *
     * PATCH /api/admin/orders/{order}/problem
     */
    public function reportProblem(Request $request, string $order): JsonResponse
    {
        $request->validate([
            'note' => 'required|string|max:1000',
        ]);

        $orderModel = Order::with(['items.product.physical', 'customer'])->findOrFail($order);

        if (!$this->orderInScope($orderModel, $request)) {
            abort(404);
        }

        $orderModel->update([
            'status' => 'needs_attention',
            'pick_note' => $request->note,
        ]);

        $shop = $request->attributes->get('shop');
        \App\Events\OrdersUpdated::dispatch($shop->id);

        $orderModel->refresh()->load(['items.product.physical', 'customer']);

        return response()->json([
            'message' => 'Заказ отмечен как проблемный',
            'data' => $this->collectorPayload($orderModel, $shop),
        ]);
    }

    /**
     * Widget: get orders by customer phone
     *
     * GET /api/widget/orders?phone=xxx
     */
    public function widgetOrdersByPhone(Request $request): JsonResponse
    {
        // Phone comes from verified token (injected by VerifyPhoneToken middleware)
        $phone = Customer::normalizePhone($request->verified_phone ?? $request->phone);

        $orders = Order::with('items')
            ->where('customer_phone', $phone)
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($order) => $this->mapOrderForWidget($order));

        return response()->json([
            'data' => $orders,
        ]);
    }

    /**
     * Widget: get orders for the logged-in mobile customer (60-day session,
     * see VerifyPhoneSession) — unlike widgetOrdersByPhone above, which relies
     * on the 30-min OTP token and is long expired by the time a returning
     * user opens the app.
     *
     * GET /api/widget/orders/mine
     */
    public function widgetOrdersMine(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('customer');

        $orders = Order::with('items')
            ->where('customer_id', $customer->id)
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($order) => $this->mapOrderForWidget($order));

        return response()->json([
            'data' => $orders,
        ]);
    }

    /**
     * Strip sensitive data for widget/mobile display — shared by
     * widgetOrdersByPhone and widgetOrdersMine.
     */
    private function mapOrderForWidget(Order $order): array
    {
        return [
            'id' => $order->id,
            'status' => $order->status,
            'total_price' => $order->total_price,
            'created_at' => $order->created_at,
            'weighed_at' => $order->weighed_at,
            'surcharge_amount' => $order->surcharge_amount,
            'surcharge_status' => $order->surcharge_status,
            'surcharge_payment_url' => $order->surcharge_payment_url,
            'surcharge_deadline_at' => $order->surcharge_deadline_at,
            'payment_url' => $order->payment_url,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'weight_grams' => $item->weight_grams,
                'actual_weight_grams' => $item->actual_weight_grams,
                'actual_price' => $item->actual_price,
            ]),
        ];
    }

    /**
     * Счётчик для бейджа «Заказы» в сайдбаре — сборщик пометил заказ
     * проблемным (needs_attention), владелец должен это заметить, даже не
     * заходя в «Заказы» (см. StaffView.vue/OrderDetailView.vue — заметка
     * там видна, но только если знать, что туда нужно зайти).
     *
     * GET /api/admin/orders/needs-attention-count
     */
    public function needsAttentionCount(Request $request): JsonResponse
    {
        $query = Order::query()->where('status', 'needs_attention');
        $this->applyCategoryScope($query, $request);

        return response()->json(['count' => $query->count()]);
    }

    /**
     * Get order statistics
     */
    public function stats(Request $request): JsonResponse
    {
        $period = $request->input('period', 'month');

        $dateFrom = match ($period) {
            'today' => now()->startOfDay()->toDateTimeString(),
            'week'  => now()->startOfWeek()->toDateTimeString(),
            default => now()->startOfMonth()->toDateTimeString(),
        };
        $dateTo = now()->toDateTimeString();

        // Also get previous period for comparison
        $prevFrom = match ($period) {
            'today' => now()->subDay()->startOfDay()->toDateTimeString(),
            'week'  => now()->subWeek()->startOfWeek()->toDateTimeString(),
            default => now()->subMonth()->startOfMonth()->toDateTimeString(),
        };
        $prevTo = match ($period) {
            'today' => now()->subDay()->endOfDay()->toDateTimeString(),
            'week'  => now()->subWeek()->endOfWeek()->toDateTimeString(),
            default => now()->subMonth()->endOfMonth()->toDateTimeString(),
        };

        $baseQuery = Order::query()->whereBetween('created_at', [$dateFrom, $dateTo]);
        $prevQuery = Order::query()->whereBetween('created_at', [$prevFrom, $prevTo]);
        $this->applyCategoryScope($baseQuery, $request);
        $this->applyCategoryScope($prevQuery, $request);

        $revenue     = (clone $baseQuery)->where('status', '!=', 'cancelled')->sum('total_price');
        $prevRevenue = (clone $prevQuery)->where('status', '!=', 'cancelled')->sum('total_price');
        $orders      = (clone $baseQuery)->count();
        $prevOrders  = (clone $prevQuery)->count();

        $stats = [
            'total_orders'       => $orders,
            'total_revenue'      => $revenue,
            'pending_orders'     => (clone $baseQuery)->where('status', 'pending')->count(),
            'paid_orders'        => (clone $baseQuery)->where('status', 'paid')->count(),
            'processing_orders'  => (clone $baseQuery)->where('status', 'processing')->count(),
            'completed_orders'   => (clone $baseQuery)->where('status', 'completed')->count(),
            'cancelled_orders'   => (clone $baseQuery)->where('status', 'cancelled')->count(),
            'average_order_value'=> (clone $baseQuery)->where('status', '!=', 'cancelled')->avg('total_price'),
            'prev_revenue'       => $prevRevenue,
            'prev_orders'        => $prevOrders,
        ];

        return response()->json($stats);
    }

    /**
     * Get daily chart data for revenue + orders
     *
     * GET /api/admin/orders/chart?days=30
     */
    public function chart(Request $request): JsonResponse
    {
        $days = min((int) $request->input('days', 30), 90);
        $from = now()->subDays($days - 1)->startOfDay();

        $chartQuery = Order::query()
            ->selectRaw("DATE(created_at) as date, COUNT(*) as orders, SUM(CASE WHEN status != 'cancelled' THEN total_price ELSE 0 END) as revenue")
            ->where('created_at', '>=', $from)
            ->groupByRaw('DATE(created_at)');
        $this->applyCategoryScope($chartQuery, $request);

        $rows = $chartQuery->orderBy('date')->get()->keyBy('date');

        // Fill all days (including zero-data days)
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row  = $rows->get($date);
            $result[] = [
                'date'    => $date,
                'orders'  => $row ? (int) $row->orders : 0,
                'revenue' => $row ? (int) $row->revenue : 0,
            ];
        }

        return response()->json(['data' => $result]);
    }

    /**
     * Delete an order with all its items (cascade)
     *
     * DELETE /api/admin/orders/{order}
     */
    public function destroy(Request $request, string $order): JsonResponse
    {
        $order = Order::with('items.product')->findOrFail($order);

        if (!$this->orderInScope($order, $request)) {
            abort(404);
        }

        // Возвращаем физические товары на склад перед удалением
        if ($order->status !== 'cancelled') {
            foreach ($order->items as $item) {
                \App\Services\PhysicalStockService::release($item);
            }
        }

        $order->items()->delete();
        $order->delete();

        return response()->json(['message' => 'Заказ удалён']);
    }

    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $query = Order::query()->with(['items', 'customer']);

        if ($request->filled('status')) {
            $query->withStatus($request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'ILIKE', "%{$search}%")
                  ->orWhere('customer_phone', 'ILIKE', "%{$search}%");
            });
        }

        $this->applyCategoryScope($query, $request);

        $orders = $query->latest('created_at')->get();
        $filename = 'orders_' . now()->format('Y-m-d') . '.csv';

        $statusLabels = [
            'pending'         => 'Ожидает',
            'paid'            => 'Оплачен',
            'processing'      => 'В работе',
            'completed'       => 'Завершён',
            'cancelled'       => 'Отменён',
            'needs_attention' => 'Требует внимания',
        ];

        return response()->streamDownload(function () use ($orders, $statusLabels) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM — чтобы Excel открывал без кракозябр
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Дата', 'Номер', 'Клиент', 'Телефон', 'Email', 'Состав', 'Сумма (₽)', 'Доставка', 'Стоимость доставки (₽)', 'Статус', 'Примечание'], ';');

            $deliveryLabels = [
                'pickup'  => 'Самовывоз',
                'courier' => 'Курьер',
                'postal'  => 'Почта / СДЭК',
            ];

            foreach ($orders as $order) {
                $items = $order->items->map(fn($i) => "{$i->product_name} ×{$i->quantity}")->implode(', ');
                $deliveryMethod = $order->delivery_method
                    ? ($deliveryLabels[$order->delivery_method] ?? $order->delivery_method)
                    : '';
                fputcsv($out, [
                    $order->created_at->format('d.m.Y H:i'),
                    strtoupper(substr($order->id, 0, 8)),
                    $order->customer?->name ?? $order->customer_name,
                    $order->customer_phone,
                    $order->customer_email,
                    $items,
                    $order->total_price,
                    $deliveryMethod,
                    $order->delivery_price ?? 0,
                    $statusLabels[$order->status] ?? $order->status,
                    $order->notes ?? '',
                ], ';');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
