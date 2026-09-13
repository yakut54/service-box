<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Support\CategoryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /**
     * Админ, ограниченный категориями (см. CategoryAccess) — клиент "свой",
     * только если у него есть хотя бы один заказ с товаром из его категорий.
     * `total_orders`/`total_spent` на самом клиенте — денормализованные и
     * по ВСЕЙ его истории (см. Customer::updateStats), отдавать их как есть
     * ограниченному админу нельзя — утечёт, сколько клиент потратил на чужие
     * категории. Пересчитываем на лету теми же правилами (см. updateStats),
     * но только по "своим" заказам.
     */
    private function scopeCustomer(Customer $customer, array $allowedCategoryIds): void
    {
        $qualifying = $customer->orders()
            ->whereHas('items.product', fn ($q) => $q->whereIn('category_id', $allowedCategoryIds))
            ->get();

        $customer->total_orders = $qualifying->count();
        $customer->total_spent  = $qualifying->where('status', '!=', 'cancelled')->sum('total_price');
    }

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

        $allowedCategoryIds = CategoryAccess::expandedIds($request);
        if ($allowedCategoryIds !== null) {
            $query->whereHas('orders.items.product', fn ($q) => $q->whereIn('category_id', $allowedCategoryIds));
        }

        $customers = $query->latest('created_at')->get();

        if ($allowedCategoryIds !== null) {
            $customers->each(fn ($c) => $this->scopeCustomer($c, $allowedCategoryIds));
        }

        return response()->json([
            'data' => $customers,
            'count' => $customers->count(),
        ]);
    }

    /**
     * Get single customer with orders
     */
    public function show(Request $request, string $customer): JsonResponse
    {
        $customer = Customer::findOrFail($customer);
        $allowedCategoryIds = CategoryAccess::expandedIds($request);

        if ($allowedCategoryIds !== null) {
            $isOwn = $customer->orders()
                ->whereHas('items.product', fn ($q) => $q->whereIn('category_id', $allowedCategoryIds))
                ->exists();
            if (!$isOwn) {
                abort(404);
            }
            $this->scopeCustomer($customer, $allowedCategoryIds);
        }

        $customer->load([
            'orders' => function ($q) use ($allowedCategoryIds) {
                $q->with('items')->latest('created_at');
                if ($allowedCategoryIds !== null) {
                    $q->whereHas('items.product', fn ($q2) => $q2->whereIn('category_id', $allowedCategoryIds));
                }
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
    public function destroy(Request $request, string $customer): JsonResponse
    {
        $customer = Customer::findOrFail($customer);

        $allowedCategoryIds = CategoryAccess::expandedIds($request);
        if ($allowedCategoryIds !== null) {
            $isOwn = $customer->orders()
                ->whereHas('items.product', fn ($q) => $q->whereIn('category_id', $allowedCategoryIds))
                ->exists();
            if (!$isOwn) {
                abort(404);
            }
        }

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

    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
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

        $allowedCategoryIds = CategoryAccess::expandedIds($request);
        if ($allowedCategoryIds !== null) {
            $query->whereHas('orders.items.product', fn ($q) => $q->whereIn('category_id', $allowedCategoryIds));
        }

        $customers = $query->latest('created_at')->get();

        if ($allowedCategoryIds !== null) {
            $customers->each(fn ($c) => $this->scopeCustomer($c, $allowedCategoryIds));
        }

        $filename = 'customers_' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($customers) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Имя', 'Телефон', 'Email', 'Заказов', 'Потрачено (₽)', 'Дата регистрации'], ';');

            foreach ($customers as $c) {
                fputcsv($out, [
                    $c->name,
                    $c->phone,
                    $c->email ?? '',
                    $c->total_orders,
                    $c->total_spent,
                    $c->created_at->format('d.m.Y'),
                ], ';');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
