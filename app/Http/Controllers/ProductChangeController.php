<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\Paginates;
use App\Http\Controllers\Concerns\TracksSeenCursor;
use App\Models\ProductChange;
use App\Support\CategoryAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Журнал ручных правок товара (см. App\Services\ProductChangeLog) — только
 * чтение, строки пишутся из ProductController через сервис, не отсюда.
 */
class ProductChangeController extends Controller
{
    use Paginates;
    use TracksSeenCursor;

    /**
     * Админ, ограниченный категориями (см. CategoryAccess) — видит только
     * строки по СВОИМ товарам. Удалённый товар (product_id уже null,
     * снапшот остался) для такого админа не виден — определить его
     * категорию больше нечем; владелец и неограниченный админ видят всё.
     */
    private function scopedQuery(Request $request): Builder
    {
        $query = ProductChange::query();

        $allowedCategoryIds = CategoryAccess::expandedIds($request);
        if ($allowedCategoryIds !== null) {
            $query->whereHas('product', fn ($q) => $q->whereIn('category_id', $allowedCategoryIds));
        }

        return $query;
    }

    /**
     * GET /api/admin/product-changes
     *
     * Query params: product_id, actor_user_id, page, per_page
     */
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $query = $this->scopedQuery($request)->orderByDesc('created_at');

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        if ($request->filled('actor_user_id')) {
            $query->where('actor_user_id', $request->input('actor_user_id'));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);

        return response()->json([
            ...$this->paginatedResponse($query->paginate($perPage)),
            // Значение ДО этого захода — фронт сравнивает created_at строк
            // с ним, чтобы подсветить те, что появились с прошлого визита.
            // См. markSeen() — фронт сам обновляет таймстемп отдельным
            // вызовом уже ПОСЛЕ того, как прочитал это значение (тот же
            // приём, что ReviewController::index).
            'product_changes_last_seen_at' => $shop->product_changes_last_seen_at?->toIso8601String(),
        ]);
    }

    /**
     * GET /api/admin/product-changes/pending-count
     * Лёгкий счётчик для бейджа в меню — сколько строк появилось с прошлого
     * визита на страницу «Журнал изменений» (не "всего записей" — это
     * отдельная серая цифра из NavCountsController).
     */
    public function pendingCount(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $count = $this->pendingCountSince($shop, 'product_changes_last_seen_at', $this->scopedQuery($request));

        return response()->json(['count' => $count]);
    }

    /**
     * POST /api/admin/product-changes/mark-seen
     * Фиксирует «я только что просматривал журнал» — двигает таймстемп, от
     * которого считается pendingCount()/подсветка новых на фронте.
     */
    public function markSeen(Request $request): JsonResponse
    {
        $this->markSeenNow($request->attributes->get('shop'), 'product_changes_last_seen_at');

        return response()->json(['message' => 'ok']);
    }
}
