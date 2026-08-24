<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Mail\NewOrderMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Services\DiscountService;
use App\Services\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{
    public function __construct(private readonly DiscountService $discountService) {}

    /**
     * Get list of orders
     *
     * Query params: status, customer_id, date_from, date_to, search
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::query()->with(['items', 'customer']);

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

        $orders = $query->latest('created_at')->get();

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

                if ($saleMode === 'piece') {
                    if (!isset($item['quantity'])) {
                        abort(422, "Не указано количество для товара «{$product->name}»");
                    }
                    $quantity  = (int) $item['quantity'];
                    $itemPrice = $product->price;

                    if ($product->type === 'physical' && $product->physical) {
                        if ($product->physical->stock_quantity < $quantity) {
                            abort(409, "Товар «{$product->name}» закончился на складе");
                        }
                        $product->physical->decrement('stock_quantity', $quantity);
                    }
                } else {
                    // weight_fixed / weight_variable — см. PLAN.md, «Развесной товар».
                    // Складской остаток по весу в этой версии не отслеживается.
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
        $order->load(['items', 'customer']);

        if ($shop && $shop->user?->email) {
            try {
                Mail::to($shop->user->email)->send(new NewOrderMail($order, $shop->name));
            } catch (\Throwable) {}
        }
        if ($shop) {
            try {
                \App\Services\TelegramService::notifyNewOrder($shop, $order);
            } catch (\Throwable) {}
            try {
                \App\Services\MaxService::notifyNewOrder($shop, $order);
            } catch (\Throwable) {}
        }

        return response()->json([
            'message' => 'Заказ успешно создан',
            'data'    => $order,
        ], 201);
    }

    /**
     * Get single order
     */
    public function show(string $order): JsonResponse
    {
        $order = Order::with(['items.product', 'customer'])->findOrFail($order);

        return response()->json([
            'data' => $order,
        ]);
    }

    /**
     * Update order status
     *
     * PATCH /api/admin/orders/{order}/status
     */
    public function updateStatus(Request $request, string $order): JsonResponse
    {
        $order = Order::with('items.product')->findOrFail($order);
        $request->validate([
            // 'paid' сюда сознательно не входит — этот статус проставляет
            // только вебхук ЮKassa (Order::markAsPaid), после реальной
            // оплаты через шлюз. Иначе шопер мог бы щёлкнуть «оплачено»
            // без единого рубля и не заплатить комиссию (см. PLAN.md).
            'status' => 'required|in:processing,completed,cancelled',
        ]);

        $newStatus = $request->status;

        if ($newStatus === 'completed' && !$order->paid_at) {
            return response()->json([
                'message' => 'Нельзя завершить неоплаченный заказ — оплата проходит только через ЮKassa',
            ], 422);
        }

        $oldStatus = $order->status;

        $order->update(['status' => $newStatus]);

        if ($newStatus === 'cancelled' && $oldStatus !== 'cancelled') {
            // Возвращаем товары на склад
            foreach ($order->items as $item) {
                if ($item->product && $item->product->type === 'physical') {
                    $item->product->physical?->increment('stock_quantity', $item->quantity);
                }
            }

            // Комиссия возвращается вместе с заказом — без исключений
            if ($order->commission_amount > 0) {
                $order->update(['commission_amount' => 0]);
            }
        }

        $order->load(['items', 'customer']);

        return response()->json([
            'message' => 'Статус заказа обновлён',
            'data' => $order,
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
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'price' => $item->price,
            ]),
        ];
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

        $rows = Order::query()
            ->selectRaw("DATE(created_at) as date, COUNT(*) as orders, SUM(CASE WHEN status != 'cancelled' THEN total_price ELSE 0 END) as revenue")
            ->where('created_at', '>=', $from)
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

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
    public function destroy(string $order): JsonResponse
    {
        $order = Order::with('items.product')->findOrFail($order);

        // Возвращаем физические товары на склад перед удалением
        foreach ($order->items as $item) {
            if ($item->product && $item->product->type === 'physical' && $order->status !== 'cancelled') {
                $item->product->physical?->increment('stock_quantity', $item->quantity);
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

        $orders = $query->latest('created_at')->get();
        $filename = 'orders_' . now()->format('Y-m-d') . '.csv';

        $statusLabels = [
            'pending'    => 'Ожидает',
            'paid'       => 'Оплачен',
            'processing' => 'В работе',
            'completed'  => 'Завершён',
            'cancelled'  => 'Отменён',
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
