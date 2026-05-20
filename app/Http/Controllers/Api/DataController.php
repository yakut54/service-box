<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Master;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DataController extends Controller
{
    private function perPage(Request $request): int
    {
        return min((int) ($request->query('per_page', 50)), 100);
    }

    private function pageMeta($paginator): array
    {
        return [
            'total'     => $paginator->total(),
            'page'      => $paginator->currentPage(),
            'per_page'  => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    // ── Bookings ─────────────────────────────────────────────────────────────

    public function booking(string $id): JsonResponse
    {
        $booking = Booking::with(['service:id,name,price', 'master:id,name'])->findOrFail($id);
        return response()->json(['data' => $booking]);
    }

    public function bookings(Request $request): JsonResponse
    {
        $query = Booking::with(['service:id,name,price', 'master:id,name']);

        if ($request->filled('status'))    { $query->withStatus($request->status); }
        if ($request->filled('master_id')) { $query->where('master_id', $request->master_id); }
        if ($request->filled('date_from')) { $query->whereDate('start_time', '>=', $request->date_from); }
        if ($request->filled('date_to'))   { $query->whereDate('start_time', '<=', $request->date_to); }

        $paginator = $query->orderBy('start_time', 'desc')->paginate($this->perPage($request));

        return response()->json(['data' => $paginator->items(), 'meta' => $this->pageMeta($paginator)]);
    }

    // ── Orders ───────────────────────────────────────────────────────────────

    public function order(string $id): JsonResponse
    {
        $order = Order::with(['items', 'customer'])->findOrFail($id);
        return response()->json(['data' => $order]);
    }

    public function orders(Request $request): JsonResponse
    {
        $query = Order::with(['items', 'customer']);

        if ($request->filled('status'))      { $query->withStatus($request->status); }
        if ($request->filled('customer_id')) { $query->where('customer_id', $request->customer_id); }
        if ($request->filled('date_from'))   { $query->whereDate('created_at', '>=', $request->date_from); }
        if ($request->filled('date_to'))     { $query->whereDate('created_at', '<=', $request->date_to); }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q
                ->where('customer_name',  'ILIKE', "%{$s}%")
                ->orWhere('customer_phone', 'ILIKE', "%{$s}%")
                ->orWhere('customer_email', 'ILIKE', "%{$s}%")
            );
        }

        $paginator = $query->latest('created_at')->paginate($this->perPage($request));

        return response()->json(['data' => $paginator->items(), 'meta' => $this->pageMeta($paginator)]);
    }

    // ── Clients ──────────────────────────────────────────────────────────────

    public function clients(Request $request): JsonResponse
    {
        $query = Customer::query();

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q
                ->where('name',  'ILIKE', "%{$s}%")
                ->orWhere('phone', 'ILIKE', "%{$s}%")
                ->orWhere('email', 'ILIKE', "%{$s}%")
            );
        }

        $paginator = $query->latest('created_at')->paginate($this->perPage($request));

        return response()->json(['data' => $paginator->items(), 'meta' => $this->pageMeta($paginator)]);
    }

    public function client(string $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        return response()->json(['data' => $customer]);
    }

    // ── Products / Services ──────────────────────────────────────────────────

    public function services(Request $request): JsonResponse
    {
        $query = Product::ofType('service')
            ->with('service:product_id,duration_minutes,requires_prepayment')
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name');

        $paginator = $query->paginate($this->perPage($request));

        return response()->json(['data' => $paginator->items(), 'meta' => $this->pageMeta($paginator)]);
    }

    public function products(Request $request): JsonResponse
    {
        $query = Product::with(['service:product_id,duration_minutes,requires_prepayment']);

        if ($request->filled('type'))   { $query->ofType($request->type); }
        if ($request->filled('active')) {
            $query->where('is_active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
        }

        $paginator = $query->orderBy('sort_order')->orderBy('name')->paginate($this->perPage($request));

        return response()->json(['data' => $paginator->items(), 'meta' => $this->pageMeta($paginator)]);
    }

    public function product(string $id): JsonResponse
    {
        $product = Product::with(['service:product_id,duration_minutes,requires_prepayment'])->findOrFail($id);
        return response()->json(['data' => $product]);
    }

    // ── Masters ──────────────────────────────────────────────────────────────

    public function masters(Request $request): JsonResponse
    {
        $query = Master::with('services:id,name');

        if ($request->filled('active')) {
            $query->where('is_active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
        }

        $paginator = $query->orderBy('sort_order')->orderBy('name')->paginate($this->perPage($request));

        return response()->json(['data' => $paginator->items(), 'meta' => $this->pageMeta($paginator)]);
    }

    public function master(string $id): JsonResponse
    {
        $master = Master::with('services:id,name')->findOrFail($id);
        return response()->json(['data' => $master]);
    }

    // ── Categories ───────────────────────────────────────────────────────────

    public function categories(): JsonResponse
    {
        $categories = Category::withCount('products')
            ->with(['children' => function ($q) {
                $q->withCount('products')->orderBy('sort_order')->orderBy('name');
            }])
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }
}
