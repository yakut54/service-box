<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\Paginates;
use App\Models\Customer;
use App\Services\TableExport;
use App\Support\CategoryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    use Paginates;

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

        if ($request->filled('page')) {
            $perPage = min((int) $request->input('per_page', 25), 100);

            // Карточки "Всего клиентов"/"Общая выручка"/"Ср. чек" — по ВСЕЙ
            // выборке (все страницы), не только по текущей, иначе цифры
            // прыгали бы при переключении страниц.
            $allMatching = (clone $query)->get();
            if ($allowedCategoryIds !== null) {
                $allMatching->each(fn ($c) => $this->scopeCustomer($c, $allowedCategoryIds));
            }
            $totalRevenue = (int) $allMatching->sum('total_spent');
            $totalOrders  = (int) $allMatching->sum('total_orders');

            $paginated = $query->orderByDesc('total_spent')->paginate($perPage);
            if ($allowedCategoryIds !== null) {
                collect($paginated->items())->each(fn ($c) => $this->scopeCustomer($c, $allowedCategoryIds));
            }

            $response = $this->paginatedResponse($paginated);
            $response['meta']['total_revenue']    = $totalRevenue;
            $response['meta']['avg_order_value']  = $totalOrders > 0 ? (int) round($totalRevenue / $totalOrders) : 0;

            return response()->json($response);
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

        $headers = ['Имя', 'Телефон', 'Email', 'Заказов', 'Потрачено (₽)', 'Дата регистрации'];

        $rows = $customers->map(fn ($c) => [
            $c->name,
            $c->phone,
            $c->email ?? '',
            $c->total_orders,
            // total_spent — в копейках (см. Customer::$casts), как и
            // total_price у заказа — делим на 100 (см. OrderController::export).
            number_format($c->total_spent / 100, 2, '.', ''),
            $c->created_at->format('d.m.Y'),
        ]);

        return TableExport::stream($request->input('format', 'csv'), 'customers', $headers, $rows);
    }
}
