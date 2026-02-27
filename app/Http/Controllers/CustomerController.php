<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /**
     * Get list of customers
     *
     * Query params: search
     */
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('phone', 'ILIKE', "%{$search}%")
                  ->orWhere('email', 'ILIKE', "%{$search}%");
            });
        }

        $customers = $query->latest('created_at')->get();

        return response()->json([
            'data' => $customers,
            'count' => $customers->count(),
        ]);
    }

    /**
     * Get single customer with orders
     */
    public function show(string $customer): JsonResponse
    {
        $customer = Customer::findOrFail($customer);
        $customer->load([
            'orders' => function ($q) {
                $q->with('items')->latest('created_at');
            },
            'bookings' => function ($q) {
                $q->with(['service', 'master'])->latest('start_time');
            },
        ]);

        return response()->json([
            'data' => $customer,
        ]);
    }

    /**
     * Cascade delete customer: OrderItems → Orders → Bookings → Customer
     *
     * DELETE /api/admin/customers/{customer}
     */
    public function destroy(string $customer): JsonResponse
    {
        $customer = Customer::findOrFail($customer);

        DB::transaction(function () use ($customer) {
            // Delete order items first (FK constraint)
            foreach ($customer->orders as $order) {
                $order->items()->delete();
            }
            $customer->orders()->delete();
            $customer->bookings()->delete();
            $customer->delete();
        });

        return response()->json(['message' => 'Клиент удалён']);
    }
}
