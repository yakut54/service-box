<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function show(Customer $customer): JsonResponse
    {
        $customer->load(['orders' => function ($q) {
            $q->with('items')->latest('created_at');
        }]);

        return response()->json([
            'data' => $customer,
        ]);
    }
}
