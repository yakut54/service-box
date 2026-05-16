<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Master;
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

    /**
     * GET /api/v1/bookings
     * Filters: status, master_id, date_from, date_to
     */
    public function bookings(Request $request): JsonResponse
    {
        $query = Booking::with(['service:id,name,price', 'master:id,name']);

        if ($request->filled('status')) {
            $query->withStatus($request->status);
        }
        if ($request->filled('master_id')) {
            $query->where('master_id', $request->master_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('start_time', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('start_time', '<=', $request->date_to);
        }

        $paginator = $query->orderBy('start_time', 'desc')->paginate($this->perPage($request));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => $this->pageMeta($paginator),
        ]);
    }

    /**
     * GET /api/v1/clients
     * Filters: search (name, phone, email)
     */
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

        return response()->json([
            'data' => $paginator->items(),
            'meta' => $this->pageMeta($paginator),
        ]);
    }

    /**
     * GET /api/v1/services
     * Returns only active services (type=service)
     */
    public function services(Request $request): JsonResponse
    {
        $query = Product::ofType('service')
            ->with('service:product_id,duration_minutes,requires_prepayment')
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name');

        $paginator = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => $this->pageMeta($paginator),
        ]);
    }

    /**
     * GET /api/v1/masters
     * Filters: active (true/false)
     */
    public function masters(Request $request): JsonResponse
    {
        $query = Master::with('services:id,name');

        if ($request->filled('active')) {
            $query->where('is_active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
        }

        $paginator = $query->orderBy('sort_order')->orderBy('name')->paginate($this->perPage($request));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => $this->pageMeta($paginator),
        ]);
    }
}
