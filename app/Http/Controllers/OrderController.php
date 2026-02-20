<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\DiscountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $order = Order::create([
            'customer_id' => $customer->id,
            'status' => 'pending',
            'customer_name' => $request->input('customer.name'),
            'customer_email' => $request->input('customer.email'),
            'customer_phone' => $request->input('customer.phone'),
            'shipping_address' => $request->shipping_address,
            'notes' => $request->notes,
        ]);

        foreach ($request->items as $item) {
            $product = Product::findOrFail($item['product_id']);

            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => $item['quantity'],
                'price' => $product->price,
                'product_name' => $product->name,
                'product_type' => $product->type,
            ]);

            if ($product->type === 'physical' && $product->physical) {
                $product->physical->decrement('stock_quantity', $item['quantity']);
            }
        }

        $order->calculateTotal();

        // Apply discount (promo code takes priority; fallback to auto-apply)
        $discount       = null;
        $discountAmount = 0;

        if ($request->filled('discount_code')) {
            try {
                $result         = $this->discountService->validate(
                    $request->discount_code,
                    $order->total_price,
                    $request->input('customer.phone'),
                );
                $discount       = $result['discount'];
                $discountAmount = $result['amount'];
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json([
                    'message' => $e->errors()['discount_code'][0] ?? 'Промокод недействителен',
                    'errors'  => $e->errors(),
                ], 422);
            }
        }

        if (!$discount) {
            $discount = $this->discountService->findAutoApply(
                $order->total_price,
                $request->input('customer.phone'),
            );
            if ($discount) {
                $discountAmount = $this->discountService->calculate($discount, $order->total_price);
            }
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
        $phone = $request->verified_phone ?? $request->phone;

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
}
