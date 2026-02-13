<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
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
        $customer->updateStats();
        $order->load(['items', 'customer']);

        return response()->json([
            'message' => 'Order created successfully',
            'data' => $order,
        ], 201);
    }

    /**
     * Get single order
     */
    public function show(Order $order): JsonResponse
    {
        $order->load(['items.product', 'customer']);

        return response()->json([
            'data' => $order,
        ]);
    }

    /**
     * Update order status
     *
     * PATCH /api/admin/orders/{order}/status
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
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
     * Get order statistics
     */
    public function stats(Request $request): JsonResponse
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->endOfMonth()->toDateString());

        $baseQuery = Order::query()->whereBetween('created_at', [$dateFrom, $dateTo]);

        $stats = [
            'total_orders' => (clone $baseQuery)->count(),
            'total_revenue' => (clone $baseQuery)->where('status', '!=', 'cancelled')->sum('total_price'),
            'pending_orders' => (clone $baseQuery)->where('status', 'pending')->count(),
            'paid_orders' => (clone $baseQuery)->where('status', 'paid')->count(),
            'completed_orders' => (clone $baseQuery)->where('status', 'completed')->count(),
            'cancelled_orders' => (clone $baseQuery)->where('status', 'cancelled')->count(),
            'average_order_value' => (clone $baseQuery)->where('status', '!=', 'cancelled')->avg('total_price'),
        ];

        return response()->json($stats);
    }
}
