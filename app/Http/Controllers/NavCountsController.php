<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\ShopStaff;
use App\Support\CategoryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Серые счётки «всего» рядом с пунктами меню сайдбара (AppLayout.vue) —
 * настоящий SQL COUNT() на каждую сущность, а не count() после ->get()
 * как у старых списочных эндпоинтов (Orders/Customers/Reviews отдают
 * count только после того как материализовали все строки — для одной
 * цифры в сайдбаре это расточительно, особенно для Товаров, где на
 * каждый ещё и N+1 детали грузятся).
 */
class NavCountsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $allowedCategoryIds = CategoryAccess::expandedIds($request);
        $allowedTopLevelIds = CategoryAccess::allowedTopLevelIds($request);

        $orders = Order::query()
            ->when($allowedCategoryIds !== null, fn ($q) => $q->whereHas(
                'items.product',
                fn ($q2) => $q2->whereIn('category_id', $allowedCategoryIds)
            ))
            ->count();

        $customers = Customer::query()
            ->when($allowedCategoryIds !== null, fn ($q) => $q->whereHas(
                'orders.items.product',
                fn ($q2) => $q2->whereIn('category_id', $allowedCategoryIds)
            ))
            ->count();

        $products = Product::query()
            ->when($allowedCategoryIds !== null, fn ($q) => $q->whereIn('category_id', $allowedCategoryIds))
            ->count();

        $categories = Category::query()
            ->whereNull('parent_id')
            ->when($allowedTopLevelIds !== null, fn ($q) => $q->whereIn('id', $allowedTopLevelIds))
            ->count();

        $discounts = Discount::query()->count();

        $reviews = Review::query()
            ->when($allowedCategoryIds !== null, fn ($q) => $q->whereHas(
                'product',
                fn ($q2) => $q2->whereIn('category_id', $allowedCategoryIds)
            ))
            ->count();

        $staff = ShopStaff::query()
            ->where('shop_id', $shop->id)
            ->when(
                $request->attributes->get('staff_role') === 'admin',
                fn ($q) => $q->where('role', 'collector'),
                fn ($q) => $q->whereIn('role', ['admin', 'collector']),
            )
            ->count();

        return response()->json([
            'orders'     => $orders,
            'customers'  => $customers,
            'products'   => $products,
            'categories' => $categories,
            'discounts'  => $discounts,
            'reviews'    => $reviews,
            'staff'      => $staff,
        ]);
    }
}
