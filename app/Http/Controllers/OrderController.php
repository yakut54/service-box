<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\Paginates;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Services\DiscountService;
use App\Services\MaxService;
use App\Services\OrderActivityLog;
use App\Services\OrderAssignmentFlow;
use App\Services\OrderCollectorCandidates;
use App\Services\OrderReweighService;
use App\Services\TableExport;
use App\Services\TelegramService;
use App\Services\TenantService;
use App\Services\YooKassaService;
use App\Support\CategoryAccess;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    use Paginates;

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

    /**
     * Единая атрибуция pick_note — используется в updateStatus()/release()/
     * reportProblem(), раньше каждый собирал этот набор полей заново и
     * забывал об авторе (живой разбор 2026-10-18: Егорка молча перезаписал
     * заметку Юлии, никто бы не понял, что она уже не её). by_name —
     * текущий/последний автор, original_by_name — исходный, не трогается
     * при повторном редактировании; фронт сравнивает их, чтобы показать
     * «отредактировано {имя}», если это не тот же человек.
     *
     * @return array<string, mixed>
     */
    private function pickNoteUpdate(Order $order, string $note, string $actorName): array
    {
        return [
            'pick_note' => $note,
            'pick_note_at' => $order->pick_note_at ?? now(),
            'pick_note_edited_at' => $order->pick_note !== null ? now() : null,
            'pick_note_by_name' => $actorName,
            'pick_note_original_by_name' => $order->pick_note_original_by_name ?? $actorName,
        ];
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
     * Способ доставки реально включён магазином (shops.delivery_settings)?
     * Покупатель мог выбрать способ, который магазин никогда не предлагал —
     * StoreOrderRequest проверяет только что значение входит в enum
     * pickup/courier/postal, не что магазин его включил.
     */
    private function deliveryMethodEnabled(?Shop $shop, string $method): bool
    {
        if (!$shop) return false;
        $config = ($shop->delivery_settings ?? [])[$method] ?? null;
        return $config !== null && !empty($config['enabled']);
    }

    /**
     * Реальная цена доставки — только из тарифов самого магазина, никогда
     * из того, что прислал клиент (см. store(): раньше $request->delivery_price
     * шёл в заказ как есть, покупатель мог отправить 0 и получить бесплатную
     * доставку — найдено аудитом безопасности 2026-09-15).
     * $goodsTotal — сумма ПОСЛЕ скидки (порог free_from считается от неё,
     * не от полной цены товаров до скидки).
     */
    private function calculateDeliveryPrice(?Shop $shop, string $method, int $goodsTotal): int
    {
        if (!$shop) return 0;
        $config = ($shop->delivery_settings ?? [])[$method] ?? null;
        if (!$config || empty($config['enabled'])) return 0;

        $freeFrom = $config['free_from'] ?? null;
        if ($freeFrom !== null && $goodsTotal >= (int) $freeFrom) return 0;

        return max(0, (int) ($config['price'] ?? 0));
    }

    /**
     * Сравнивает фактически собранное с уже списанной суммой и возвращает
     * разницу покупателю, если собрали меньше. Считает по каждой позиции
     * заново (не полагается на текущий order.total_price — тот для
     * weight_variable-заказов уже мог быть уменьшен OrderReweighService,
     * и эта функция должна урезать ЕЩЁ дальше, если вдобавок не хватило
     * штучного товара, а не наоборот). Возвращает текст ошибки, если возврат
     * не удался (тогда завершение заказа блокируется — лучше попросить
     * попробовать снова, чем молча оставить покупателя без части денег).
     */
    private function refundShortageIfAny(Order $order, ?Shop $shop): ?string
    {
        $actualTotal = $order->items->sum(function ($item) {
            if ($item->product?->physical?->sale_mode === 'weight_variable') {
                return $item->actual_price ?? ($item->price * $item->quantity);
            }
            return $item->price * ($item->picked_qty ?? $item->quantity);
        }) + $order->delivery_price;

        $shortfall = $order->total_price - $actualTotal;
        if ($shortfall <= 0) {
            return null;
        }

        if (!$shop || !$shop->yookassa_shop_id || !$shop->yookassa_secret_key || !$order->payment_id) {
            Log::warning('Order shortage refund skipped — no payment gateway configured', ['order_id' => $order->id]);
            $order->update(['total_price' => $actualTotal]);
            return null;
        }

        try {
            (new YooKassaService($shop->yookassa_shop_id, $shop->yookassa_secret_key))
                ->refund($order->payment_id, $shortfall / 100);
        } catch (\Throwable $e) {
            Log::error('Order shortage refund failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            return 'Не удалось оформить возврат за недостающий товар — попробуйте ещё раз через минуту';
        }

        $order->update(['total_price' => $actualTotal]);

        return null;
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
            $data['payment_method_id'],
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

        // Владелец/админ тоже могут зайти на экран сборки «Собрать самому»
        // (2026-10-18, см. PLAN.md) — тот же CollectorOrdersView.vue, та же
        // очередь, просто роль другая. Раньше очередь включалась только по
        // роли collector; теперь — по явному scope в запросе (обычный
        // OrdersView.vue его никогда не шлёт, так что для него ничего не
        // меняется).
        $isQueueView = $isCollector || $request->filled('scope');

        // items.product.physical — сборщику (роль collector) нужен sale_mode и
        // вес каждой позиции, чтобы показать поле ввода факт. веса только для
        // weight_variable (см. CollectorOrderDetailView.vue).
        $query = Order::query()->with(['items.product.physical', 'customer']);

        if ($isQueueView) {
            if ($request->input('scope') === 'done') {
                // «Собранные сегодня» — по таймзоне магазина, не сервера.
                // collected добавлен 2026-10-17: «Готово» у сборщика теперь
                // ведёт в collected, не completed — без этого статуса здесь
                // вкладка «Собранные сегодня» показывала бы пусто для всего,
                // что сборщик закончил, но магазин ещё не передал в доставку
                // / не закрыл сам.
                $todayStart = now($shop->timezone ?? 'Europe/Moscow')->startOfDay();
                $query->whereIn('status', ['collected', 'completed', 'cancelled'])
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

        if ($isQueueView) {
            // Очередь сборщика маленькая по определению (только активные
            // заказы одного магазина) — пагинация тут не нужна.
            $orders = $query->oldest('created_at')->get();

            return response()->json([
                'data' => $orders->map(fn ($o) => $this->collectorPayload($o, $shop))->values(),
                'count' => $orders->count(),
            ]);
        }

        // needs_attention всегда первыми, а не где придётся по дате — иначе
        // пагинация могла законно унести проблемный заказ на 2-3 страницу,
        // и владелец бы его просто не увидел, не листая дальше.
        $orderByNeedsAttention = fn ($q) => $q
            ->orderByRaw("(status = 'needs_attention') DESC")
            ->latest('created_at');

        if ($request->filled('page')) {
            $perPage = min((int) $request->input('per_page', 25), 100);
            return response()->json($this->paginatedResponse($orderByNeedsAttention($query)->paginate($perPage)));
        }

        $orders = $orderByNeedsAttention($query)->get();

        return response()->json([
            'data' => $orders,
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

        if ($request->filled('delivery_method') && !$this->deliveryMethodEnabled($shop, $request->delivery_method)) {
            return response()->json(['message' => 'Этот способ доставки недоступен в этом магазине'], 422);
        }

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
                // Не то, что прислал клиент — реальная цена считается ниже,
                // из тарифов самого магазина (см. calculateDeliveryPrice()).
                // Раньше здесь слепо доверяли $request->delivery_price —
                // покупатель мог отправить 0 и получить бесплатную доставку
                // там, где она должна быть платной (аудит безопасности
                // 2026-09-15).
                'delivery_price'          => 0,
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

        // Add delivery cost after discount (discount applies to goods only).
        // Цена — только из тарифов магазина (shops.delivery_settings), не из
        // того, что прислал клиент (см. проверку выше и калькулятор ниже).
        $deliveryPrice = $order->delivery_method
            ? $this->calculateDeliveryPrice($shop, $order->delivery_method, $order->total_price)
            : 0;
        if ($deliveryPrice > 0) {
            $order->update(['delivery_price' => $deliveryPrice, 'total_price' => $order->total_price + $deliveryPrice]);
            $order->refresh();
        }

        $customer->updateStats();
        $order->load(['items.product.physical', 'customer']);

        // «Заказал сегодня» — гейт для промо-рассылок на сутки (см. Notifier).
        if ($shop) {
            \App\Services\Notifier::markOrdered($shop->id, $customer->id);
        }

        // Заказ оформлен — корзина больше не брошена. Мобилка и сама пришлёт
        // items_count=0 после CartState.clear() при следующей синхронизации,
        // это подстраховка на случай гонки/сетевого сбоя между отправкой
        // заказа и очисткой корзины на клиенте.
        \App\Models\CartActivity::where('customer_id', $customer->id)->delete();

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

        // Открыл именно ЭТОТ проблемный заказ — бейдж в сайдбаре должен
        // уменьшиться сразу на 1, а не когда просто зашёл в раздел «Заказы»
        // (было так, не понравилось — см. живой тест 2026-09-14). Личная
        // отметка (order_seen), не общий orders.seen_at — баг найден живьём
        // 2026-10-17: Ховар открыл проблемный заказ, бейдж погас и у Юлии,
        // хотя она его не видела (тот же класс бага, что уже чинили для
        // chat_thread_reads).
        if (!$isCollector && $order->status === 'needs_attention') {
            $alreadySeen = DB::table('order_seen')
                ->where('order_id', $order->id)
                ->where('user_id', $request->user()->id)
                ->exists();

            if (!$alreadySeen) {
                DB::table('order_seen')->insert([
                    'order_id' => $order->id,
                    'user_id'  => $request->user()->id,
                    'seen_at'  => now(),
                ]);

                $shop = $request->attributes->get('shop');
                if ($shop) {
                    \App\Events\OrdersUpdated::dispatch($shop->id);
                }
            }
        }

        $payload = $isCollector
            ? $this->collectorPayload($order, $request->attributes->get('shop'))
            : $order->toArray();

        // «Юля не отвечает на заказ — она вообще сейчас в сети?» (живой
        // разбор 2026-10-18). Тот же источник истины, что уже есть для
        // «Активен» в Команде (StaffController::index) — живой
        // Sanctum-токен, а не last_login_at (не отражает выход) и не
        // WS-presence (требует открытой вкладки прямо сейчас, не
        // переживает обрыв соединения). Один запрос, не на список — только
        // когда реально открыли карточку конкретного заказа.
        $payload['collector_is_online'] = $order->collector_id
            ? \App\Models\User::isOnline($order->collector_id)
            : null;

        return response()->json(['data' => $payload]);
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

        // Владелец/админ тоже может лично собирать заказ (claim → чек-лист →
        // «Готово», см. CollectorOrderDetailView.vue, 2026-10-18) — чек-лист
        // и форма ответа ниже должны сработать для НЕГО ТОЖЕ, не только для
        // роли collector, иначе он мог бы обойти чек-лист прямо с экрана
        // сборки через ту же кнопку.
        $isSelfCollecting = $order->collector_id && $order->collector_id === $request->user()->id;

        $request->validate([
            // 'paid' сюда сознательно не входит — этот статус проставляет
            // только вебхук ЮKassa (Order::markAsPaid), после реальной
            // оплаты через шлюз. Иначе шопер мог бы щёлкнуть «оплачено»
            // без единого рубля и не заплатить комиссию (см. PLAN.md).
            // Сборщик отдельно не может «отменить» — это решение владельца/
            // админа, у сборщика для проблемных заказов есть /problem.
            // collected — сборщик сам и собирает, и (для самовывоза) часто
            // сам же отдаёт, поэтому completed у него тоже остаётся.
            'status' => $isCollector
                ? 'required|in:collected,completed'
                : 'required|in:processing,collected,in_delivery,completed,cancelled',
            'note'   => 'nullable|string|max:1000',
        ]);

        $newStatus = $request->status;

        // Переоткрыть завершённый/отменённый заказ (или перескочить мимо
        // предусмотренных шагов) запросом было нечем не остановить — см.
        // Order::TRANSITIONS.
        if ($newStatus !== $order->status && !$order->canTransitionTo($newStatus)) {
            return response()->json([
                'message' => "Нельзя сменить статус «" . (Order::STATUS_LABELS[$order->status] ?? $order->status)
                    . "» на «" . (Order::STATUS_LABELS[$newStatus] ?? $newStatus) . "»",
            ], 422);
        }

        // collected — новый промежуточный статус (2026-10-17): раньше
        // сборщик вёл заказ сразу в completed, все эти проверки (оплата,
        // доплата, чек-лист сборки) были только на completed. Теперь они
        // срабатывают уже на collected — именно туда теперь ведёт «Готово»
        // у сборщика, не в completed напрямую.
        $isFinalizing = in_array($newStatus, ['collected', 'completed'], true);

        if ($isFinalizing && !$order->paid_at) {
            return response()->json([
                'message' => 'Нельзя завершить неоплаченный заказ — оплата проходит только через ЮKassa',
            ], 422);
        }

        if ($isFinalizing && $order->surcharge_status === 'pending') {
            return response()->json([
                'message' => 'Нельзя завершить заказ — покупатель ещё не подтвердил доплату за перевзвешенный товар',
            ], 422);
        }

        // Сборщик завершает заказ только когда разобрал каждую позицию —
        // тапом (picked_qty) или взвешиванием (actual_weight_grams для
        // weight_variable). Гейт — роль collector ИЛИ тот, кто лично собирает
        // этот заказ ($isSelfCollecting, см. выше): владелец/админ, который
        // НЕ брал заказ в сборку, по-прежнему может завершить его напрямую
        // из обычной админки, не проходя чек-лист (не у всех магазинов вообще
        // есть сборщик) — но если он сам встал на сборку, чек-лист обязателен
        // точно так же, как у сборщика.
        if ($isFinalizing && ($isCollector || $isSelfCollecting)) {
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

            // Причина недобора — та же заметка (pick_note), что и «Проблема
            // с заказом», не отдельное поле на этом экране (было — убрали,
            // путало двумя местами ввода одного и того же текста).
            if ($hasShortage && !$order->pick_note && !$request->filled('note')) {
                return response()->json(['message' => 'Укажите причину недобора через «Проблема с заказом»'], 422);
            }
        }

        // Недобор штучного товара (собрали меньше, чем заказали) должен
        // уменьшать сумму заказа — покупатель не должен платить за то, чего
        // не получил. Симметрично уже существующей доплате за перевес
        // весового товара (см. OrderReweighService), только в обратную
        // сторону: там деньги ещё на холде, тут уже списаны, поэтому не
        // "списать меньше", а настоящий возврат через ЮKassa.
        if ($isFinalizing) {
            // Идемпотентно само по себе: refundShortageIfAny сразу же
            // подгоняет total_price под фактическую сумму, так что при
            // следующем вызове (например, collected → completed) shortfall
            // уже посчитается нулевым — повторного возврата не будет.
            $refundError = $this->refundShortageIfAny($order, $request->attributes->get('shop'));
            if ($refundError) {
                return response()->json(['message' => $refundError], 422);
            }
        }

        $oldStatus = $order->status;

        $update = ['status' => $newStatus];
        if ($request->filled('note')) {
            // Та же заметка (pick_note), что и у «Проблема с заказом» —
            // единая атрибуция через pickNoteUpdate(), см. её докблок.
            $update = array_merge($update, $this->pickNoteUpdate($order, $request->note, $request->user()->name));
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

        if ($newStatus !== $oldStatus) {
            OrderActivityLog::record($order, $request->user()->id, $request->user()->name, $request->attributes->get('staff_role'), 'status_changed', $oldStatus, $newStatus, $request->input('note'));
        }

        $shop = $request->attributes->get('shop');
        if ($newStatus !== $oldStatus && $shop) {
            \App\Events\OrdersUpdated::dispatch($shop->id);
        }

        // Заказ только что собран — раньше владелец узнавал об этом только
        // зайдя в админку руками, никакого уведомления не было вообще
        // (найдено живым разбором 2026-10-17). Общий чат магазина — личного
        // канала у владельца нет, см. докблок notifyOwnerOrderCollected.
        if ($newStatus === 'collected' && $oldStatus !== 'collected' && $shop) {
            if ($shop->telegram_bot_connected) {
                try { TelegramService::notifyOwnerOrderCollected($shop, $order); } catch (\Throwable $e) { Log::warning('notifyOwnerOrderCollected (telegram) failed', ['error' => $e->getMessage()]); }
            }
            if ($shop->max_bot_connected) {
                try { MaxService::notifyOwnerOrderCollected($shop, $order); } catch (\Throwable $e) { Log::warning('notifyOwnerOrderCollected (max) failed', ['error' => $e->getMessage()]); }
            }

            // Админы — личная привязка (self-service, см.
            // MasterPortalController), независимо от shop-wide ботов, как
            // и уведомления мастеру (2026-10-17: раньше у админов такого
            // канала не было вообще).
            try { TelegramService::notifyAdminsOrderCollected($shop, $order); } catch (\Throwable $e) { Log::warning('notifyAdminsOrderCollected (telegram) failed', ['error' => $e->getMessage()]); }
            try { MaxService::notifyAdminsOrderCollected($shop, $order); } catch (\Throwable $e) { Log::warning('notifyAdminsOrderCollected (max) failed', ['error' => $e->getMessage()]); }
        }

        $order->load(['items.product.physical', 'customer']);

        return response()->json([
            'message' => 'Статус заказа обновлён',
            'data' => ($isCollector || $isSelfCollecting) ? $this->collectorPayload($order, $shop) : $order,
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
        // Владелец/админ лично собирает этот заказ (см. updateStatus) — тот
        // же экран сборки, та же форма ответа.
        $isSelfCollecting = $orderModel->collector_id && $orderModel->collector_id === $request->user()->id;

        return response()->json([
            'message' => 'Вес подтверждён',
            'data' => ($isCollector || $isSelfCollecting) ? $this->collectorPayload($orderModel, $shop) : $orderModel,
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

        // ID ПОЛЬЗОВАТЕЛЯ, не staff_id — у владельца своей записи в shop_staff
        // нет (staff_id = null), а claim() теперь зовёт и он тоже. staff_id
        // здесь никогда и ни с чем не джойнится (shop_staff — другая схема),
        // только сравнивается сам с собой, так что user_id работает так же,
        // но не ломается для владельца.
        //
        // Сама запись (status+collector вместе) — в Order::claimBy(), один
        // источник истины на админку и на бот (2026-10-18, см. PLAN.md).
        $result = $orderModel->claimBy($request->user()->id, $request->user()->name, $request->boolean('takeover'));

        if (!$result['ok']) {
            $status = ($result['message'] ?? '') === 'Этот заказ уже закрыт' ? 422 : 409;
            return response()->json(collect($result)->except('ok')->all(), $status);
        }

        $shop = $request->attributes->get('shop');
        OrderActivityLog::record($orderModel, $request->user()->id, $request->user()->name, $request->attributes->get('staff_role'), 'claimed');
        \App\Events\OrdersUpdated::dispatch($shop->id);

        $orderModel->refresh()->load(['items.product.physical', 'customer']);

        return response()->json([
            'message' => 'Заказ взят в работу',
            'data' => $this->collectorPayload($orderModel, $shop),
        ]);
    }

    /**
     * Отказаться от заказа — освободить claim. Прогресс сборки
     * (picking_started_at, picked_qty по товарам) не трогаем — следующий
     * исполнитель видит, что уже сделано. Владелец/админ могут освободить
     * любой заказ; сборщик — только свой.
     *
     * Статус переводим в needs_attention (кроме уже закрытых) — раньше
     * статус вообще не трогался, и заказ мог висеть "В работе" без единого
     * исполнителя, что выглядело как заказ в работе, хотя им никто не
     * занимается (баг найден живым разбором 2026-10-17). needs_attention —
     * существующая командная очередь: видна и действуема любым админом или
     * сборщиком с доступом по категориям, не только владельцем.
     *
     * Заметка обязательна (2026-10-18) — раньше отказ был в один клик без
     * причины, следующий, кто открывал needs_attention заказ, не понимал,
     * что случилось (живой разбор). Та же колонка pick_note, что у
     * reportProblem() — одно место для причины, не два разных поля.
     *
     * PATCH /api/admin/orders/{order}/release
     */
    public function release(Request $request, string $order): JsonResponse
    {
        $request->validate([
            'note' => 'required|string|max:1000',
        ]);

        $orderModel = Order::findOrFail($order);

        if (!$this->orderInScope($orderModel, $request)) {
            abort(404);
        }

        if (!$orderModel->collector_id) {
            return response()->json(['message' => 'Заказ никем не занят'], 422);
        }

        if ($request->attributes->get('staff_role') === 'collector' && $orderModel->collector_id !== $request->user()->id) {
            return response()->json(['message' => 'Это не ваш заказ'], 403);
        }

        $becomesNeedsAttention = !in_array($orderModel->status, ['completed', 'cancelled', 'needs_attention'], true);

        $orderModel->update(array_merge([
            'collector_id' => null,
            'collector_name' => null,
            'status' => $becomesNeedsAttention ? 'needs_attention' : $orderModel->status,
        ], $this->pickNoteUpdate($orderModel, $request->note, $request->user()->name)));

        // Свежий needs_attention — все сотрудники должны снова увидеть заказ
        // непрочитанным (см. order_seen, докблок в show()/needsAttentionCount).
        if ($becomesNeedsAttention) {
            DB::table('order_seen')->where('order_id', $orderModel->id)->delete();
        }

        OrderActivityLog::record($orderModel, $request->user()->id, $request->user()->name, $request->attributes->get('staff_role'), 'released', null, null, $request->note);

        $shop = $request->attributes->get('shop');
        \App\Events\OrdersUpdated::dispatch($shop->id);

        // Владелец должен узнать сразу, кто и почему отказался — иначе
        // заказ тихо висит в needs_attention до следующего, кто туда
        // заглянет (симметрично уже существующему notifyOwnerOrderProblem).
        if ($shop->telegram_bot_connected) {
            try { TelegramService::notifyOwnerOrderReleased($shop, $orderModel, $request->user()->name, $request->note); } catch (\Throwable $e) { Log::warning('notifyOwnerOrderReleased (telegram) failed', ['error' => $e->getMessage()]); }
        }
        if ($shop->max_bot_connected) {
            try { MaxService::notifyOwnerOrderReleased($shop, $orderModel, $request->user()->name, $request->note); } catch (\Throwable $e) { Log::warning('notifyOwnerOrderReleased (max) failed', ['error' => $e->getMessage()]); }
        }

        return response()->json(['message' => 'Вы отказались от заказа']);
    }

    /**
     * Владелец/админ выбирает исполнителя из списка (кнопка «В работу» в
     * обычной карточке заказа) — тот же OrderAssignmentFlow, что и у бота,
     * единая точка записи (PLAN.md 2026-10-19). Себя — claim сразу, кого-то
     * другого — запрос, ждущий подтверждения.
     *
     * POST /api/admin/orders/{order}/request-assignment
     */
    public function requestAssignment(Request $request, string $order): JsonResponse
    {
        $request->validate(['candidate_id' => 'required|string']);

        $orderModel = Order::findOrFail($order);
        if (!$this->orderInScope($orderModel, $request)) {
            abort(404);
        }

        $shop = $request->attributes->get('shop');
        $result = OrderAssignmentFlow::request($orderModel, $shop, $request->user()->id, $request->candidate_id);

        if (!$result['ok']) {
            return response()->json(collect($result)->except('ok')->all(), 422);
        }

        \App\Events\OrdersUpdated::dispatch($shop->id);

        return response()->json([
            'message' => $result['pending']
                ? "Запрос отправлен {$result['actor_name']}"
                : "Заказ закреплён за {$result['actor_name']}",
            'pending' => $result['pending'],
        ]);
    }

    /**
     * Список кандидатов с онлайн-статусом для модалки выбора исполнителя —
     * то же самое, что видит бот (OrderCollectorCandidates::listFor()).
     *
     * GET /api/admin/orders/{order}/assignment-candidates
     */
    public function assignmentCandidates(Request $request, string $order): JsonResponse
    {
        $orderModel = Order::findOrFail($order);
        if (!$this->orderInScope($orderModel, $request)) {
            abort(404);
        }

        $shop = $request->attributes->get('shop');

        return response()->json(['data' => OrderCollectorCandidates::listFor($shop)]);
    }

    /**
     * Кандидат подтверждает запрос ("Беру") — только теперь реально
     * claimBy() (PLAN.md 2026-10-19).
     *
     * PATCH /api/admin/orders/{order}/accept-assignment
     */
    public function acceptAssignment(Request $request, string $order): JsonResponse
    {
        $orderModel = Order::findOrFail($order);
        if (!$this->orderInScope($orderModel, $request)) {
            abort(404);
        }

        $shop = $request->attributes->get('shop');
        $result = OrderAssignmentFlow::accept($orderModel, $shop, $request->user()->id);

        if (!$result['ok']) {
            return response()->json(collect($result)->except('ok')->all(), 422);
        }

        \App\Events\OrdersUpdated::dispatch($shop->id);

        $text = "Заказ #" . substr($orderModel->id, 0, 8) . " — ✅ В работе, собирает {$result['actor_name']}";
        if ($shop->telegram_bot_connected) {
            try { TelegramService::sendMessage($shop, $text); } catch (\Throwable $e) { Log::warning('acceptAssignment notify (telegram) failed', ['error' => $e->getMessage()]); }
        }
        if ($shop->max_bot_connected) {
            try { MaxService::sendMessage($shop, $text); } catch (\Throwable $e) { Log::warning('acceptAssignment notify (max) failed', ['error' => $e->getMessage()]); }
        }

        return response()->json(['message' => 'Вы взяли заказ в работу']);
    }

    /**
     * Кандидат отказывается ("Не беру") — claim так и не произошёл, владелец
     * узнаёт сразу и видит список кандидатов заново (симметрично
     * notifyOwnerOrderProblem — не ждём, пока кто-то случайно заметит).
     *
     * PATCH /api/admin/orders/{order}/decline-assignment
     */
    public function declineAssignment(Request $request, string $order): JsonResponse
    {
        $orderModel = Order::findOrFail($order);
        if (!$this->orderInScope($orderModel, $request)) {
            abort(404);
        }

        $shop = $request->attributes->get('shop');
        $result = OrderAssignmentFlow::decline($orderModel, $shop, $request->user()->id);

        if (!$result['ok']) {
            return response()->json(collect($result)->except('ok')->all(), 422);
        }

        \App\Events\OrdersUpdated::dispatch($shop->id);

        $options = OrderCollectorCandidates::listFor($shop);
        OrderCollectorCandidates::cache($orderModel->id, $options);

        if ($shop->telegram_bot_connected) {
            try { TelegramService::notifyAssignmentDeclined($shop, $orderModel, $result['decliner_name'], $options); } catch (\Throwable $e) { Log::warning('declineAssignment notify (telegram) failed', ['error' => $e->getMessage()]); }
        }
        if ($shop->max_bot_connected) {
            try { MaxService::notifyAssignmentDeclined($shop, $orderModel, $result['decliner_name'], $options); } catch (\Throwable $e) { Log::warning('declineAssignment notify (max) failed', ['error' => $e->getMessage()]); }
        }

        return response()->json(['message' => 'Вы отклонили заказ']);
    }

    /**
     * История действий с заказом (claim/release/смена статуса/проблема) —
     * см. OrderActivityLog. Доступна всем ролям, у кого есть доступ к
     * самому заказу (та же видимость, что show()).
     *
     * GET /api/admin/orders/{order}/activity
     */
    public function activity(Request $request, string $order): JsonResponse
    {
        $orderModel = Order::findOrFail($order);

        if (!$this->orderInScope($orderModel, $request)) {
            abort(404);
        }

        $rows = $orderModel->activities()->orderByDesc('created_at')->get();

        return response()->json(['data' => $rows]);
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

        // Тот же класс дыры, что чинили в updateStatus (Order::TRANSITIONS) —
        // этот метод тоже меняет статус (на needs_attention) и тоже не
        // проверял, с какого. Закрытый заказ трогать нельзя; needs_attention
        // → needs_attention разрешаем отдельно — это не переход, а
        // редактирование уже существующей заметки ($isEdit ниже).
        if (in_array($orderModel->status, ['completed', 'cancelled'], true)) {
            return response()->json(['message' => 'Этот заказ уже закрыт'], 422);
        }

        // Заметку можно переотправить (исправить текст) сколько угодно раз,
        // пока заказ активен — атрибуция (кто написал/кто правил последним)
        // через pickNoteUpdate(), см. её докблок.
        $oldStatus = $orderModel->status;

        $orderModel->update(array_merge(
            ['status' => 'needs_attention'],
            $this->pickNoteUpdate($orderModel, $request->note, $request->user()->name),
        ));

        // Свежий needs_attention — см. комментарий в release() выше.
        DB::table('order_seen')->where('order_id', $orderModel->id)->delete();

        OrderActivityLog::record($orderModel, $request->user()->id, $request->user()->name, $request->attributes->get('staff_role'), 'problem_reported', $oldStatus, 'needs_attention', $request->note);

        $shop = $request->attributes->get('shop');

        // Раньше владелец узнавал о проблеме только по беззвучному realtime-
        // эвенту (если вкладка админки открыта) — теперь дополнительно шлём
        // в Telegram/MAX, тот же канал/приём, что у
        // notifyOwnerSurchargeExpired (см. CheckSurchargeDeadline).
        if ($shop->telegram_bot_connected) {
            try { TelegramService::notifyOwnerOrderProblem($shop, $orderModel, $request->note); } catch (\Throwable $e) { Log::warning('notifyOwnerOrderProblem (telegram) failed', ['error' => $e->getMessage()]); }
        }
        if ($shop->max_bot_connected) {
            try { MaxService::notifyOwnerOrderProblem($shop, $orderModel, $request->note); } catch (\Throwable $e) { Log::warning('notifyOwnerOrderProblem (max) failed', ['error' => $e->getMessage()]); }
        }

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
     * Покупатель подтверждает получение заказа от курьера — «в доставке»
     * раньше не имело выхода без ручного вмешательства магазина: роли/
     * сущности курьера в системе нет (подтверждено при проектировании
     * статуса 2026-10-17), курьер — реальный человек, который просто везёт
     * заказ, никуда в систему не заходит. Единственный, кто физически знает,
     * что заказ доехал, — сам покупатель.
     *
     * Только мобильная сессия (verify.phone.session) — в вебе (виджете) для
     * этой кнопки сначала нужно протащить телефон/токен через MyOrders.vue
     * (см. PLAN.md, сознательно не делаем в этом заходе).
     *
     * Без OrderActivityLog::record() — тот рассчитан на залогиненного
     * сотрудника (staff_id/staff_name в сессии), не на покупателя; известный,
     * осознанный пробел истории заказа, не блокирующий.
     *
     * POST /api/widget/orders/{order}/confirm-received
     */
    public function confirmReceived(Request $request, string $order): JsonResponse
    {
        $customer = $request->attributes->get('customer');
        if (!$customer) {
            return response()->json(['message' => 'Нужна авторизация'], 401);
        }

        $orderModel = Order::findOrFail($order);

        if ($orderModel->customer_id !== $customer->id) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        if ($orderModel->status !== 'in_delivery') {
            return response()->json([
                'message' => 'Заказ нельзя подтвердить — он не в доставке (статус: '
                    . (Order::STATUS_LABELS[$orderModel->status] ?? $orderModel->status) . ')',
            ], 422);
        }

        $orderModel->update(['status' => 'completed']);

        \App\Jobs\SendOrderStatusPush::dispatchFor($orderModel, 'completed');

        $shop = $request->get('_shop');
        if ($shop) {
            \App\Events\OrdersUpdated::dispatch($shop->id);
        }

        return response()->json(['message' => 'Получение подтверждено', 'data' => $orderModel]);
    }

    /**
     * Счётчик для бейджа «Заказы» в сайдбаре — считает needs_attention,
     * которые ЭТОТ сотрудник ещё не открыл сам (не просто зашёл в раздел
     * «Заказы» — так было и не понравилось, см. живой тест 2026-09-14).
     * Личная отметка (order_seen), не общий orders.seen_at — см. докблок в
     * show(). Строка в order_seen для заказа удаляется при каждом новом
     * попадании в needs_attention (reportProblem, release,
     * CheckSurchargeDeadline) — все сотрудники снова видят его непрочитанным.
     * Сортировка «проблемные — наверх списка» на это не влияет и остаётся
     * как есть.
     *
     * GET /api/admin/orders/needs-attention-count
     */
    public function needsAttentionCount(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $query = Order::query()
            ->where('status', 'needs_attention')
            ->whereNotExists(function ($q) use ($userId) {
                $q->selectRaw('1')
                  ->from('order_seen')
                  ->whereColumn('order_seen.order_id', 'orders.id')
                  ->where('order_seen.user_id', $userId);
            });
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
            'collected_orders'   => (clone $baseQuery)->where('status', 'collected')->count(),
            'in_delivery_orders' => (clone $baseQuery)->where('status', 'in_delivery')->count(),
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

        $statusLabels = [
            'pending'         => 'Ожидает',
            'paid'            => 'Оплачен',
            'processing'      => 'В работе',
            'collected'       => 'Собрано',
            'in_delivery'     => 'В доставке',
            'completed'       => 'Завершён',
            'cancelled'       => 'Отменён',
            'needs_attention' => 'Требует внимания',
        ];
        $deliveryLabels = [
            'pickup'  => 'Самовывоз',
            'courier' => 'Курьер',
            'postal'  => 'Почта / СДЭК',
        ];

        $headers = ['Дата', 'Номер', 'Клиент', 'Телефон', 'Email', 'Состав', 'Сумма (₽)', 'Доставка', 'Стоимость доставки (₽)', 'Статус', 'Примечание'];

        $rows = $orders->map(function ($order) use ($statusLabels, $deliveryLabels) {
            $items = $order->items->map(fn($i) => "{$i->product_name} ×{$i->quantity}")->implode(', ');
            $deliveryMethod = $order->delivery_method
                ? ($deliveryLabels[$order->delivery_method] ?? $order->delivery_method)
                : '';

            return [
                $order->created_at->format('d.m.Y H:i'),
                strtoupper(substr($order->id, 0, 8)),
                $order->customer?->name ?? $order->customer_name,
                $order->customer_phone,
                $order->customer_email,
                $items,
                // В базе суммы в копейках (см. Order::$casts) — делим на 100,
                // иначе в выгрузке уходят копейки под видом рублей (заказ на
                // 95₽ попадал в файл как «9500»). Формат — простое число с
                // точкой, без разделителя тысяч, чтобы Excel/Таблицы читали
                // колонку как число, а не текст.
                number_format($order->total_price / 100, 2, '.', ''),
                $deliveryMethod,
                number_format(($order->delivery_price ?? 0) / 100, 2, '.', ''),
                $statusLabels[$order->status] ?? $order->status,
                $order->notes ?? '',
            ];
        });

        return TableExport::stream($request->input('format', 'csv'), 'orders', $headers, $rows);
    }

    /**
     * Печатная накладная по одному заказу — не фискальный документ (в
     * shops нет ИНН/юр. адреса), практическая упаковочная форма для
     * распечатки/приложить к заказу. DejaVu Sans (идёт в комплекте с
     * dompdf) поддерживает кириллицу без доп. настройки шрифтов.
     *
     * GET /api/admin/orders/{order}/invoice
     */
    public function invoice(Request $request, string $order): Response
    {
        $orderModel = Order::with(['items.product', 'customer'])->findOrFail($order);

        if (!$this->orderInScope($orderModel, $request)) {
            abort(404);
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('invoices.order', [
            'order' => $orderModel,
            'shop'  => $request->attributes->get('shop'),
        ])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="order-' . strtoupper(substr($orderModel->id, 0, 8)) . '.pdf"',
        ]);
    }
}
