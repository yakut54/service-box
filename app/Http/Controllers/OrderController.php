<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\DiscountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                $product = Product::with('physical')->lockForUpdate()->findOrFail($item['product_id']);

                if ($product->type === 'physical' && $product->physical) {
                    if ($product->physical->stock_quantity < $item['quantity']) {
                        abort(409, "Товар «{$product->name}» закончился на складе");
                    }
                    $product->physical->decrement('stock_quantity', $item['quantity']);
                }

                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'price' => $product->price,
                    'product_name' => $product->name,
                    'product_type' => $product->type,
                ]);

                $cartItems[] = [
                    'product_id'  => $product->id,
                    'category_id' => $product->category_id ?? null,
                    'quantity'    => $item['quantity'],
                    'price'       => $product->price,
                ];
            }

            $order->calculateTotal();

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

        $customer->updateStats();
        $order->load(['items', 'customer']);

        return response()->json([
            'message' => 'Order created successfully',
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
            'status' => 'required|in:pending,paid,processing,completed,cancelled',
        ]);

        $oldStatus = $order->status;

        $order->update([
            'status' => $request->status,
        ]);

        // Если отменяем — возвращаем товары на склад
        if ($request->status === 'cancelled' && $oldStatus !== 'cancelled') {
            foreach ($order->items as $item) {
                if ($item->product && $item->product->type === 'physical') {
                    $item->product->physical?->increment('stock_quantity', $item->quantity);
                }
            }
        }

        $order->load(['items', 'customer']);

        return response()->json([
            'message' => 'Order status updated',
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
            ->map(function ($order) {
                // Strip sensitive data for widget display
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
            });

        return response()->json([
            'data' => $orders,
        ]);
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
}
