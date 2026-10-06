<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\Paginates;
use App\Models\StockAdjustment;
use App\Support\CategoryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Журнал ручных правок остатка (см. App\Services\StockJournal) — только
 * чтение, строки пишутся из ProductController через сервис, не отсюда.
 */
class StockJournalController extends Controller
{
    use Paginates;

    /**
     * GET /api/admin/stock-journal
     *
     * Query params: product_id, actor_user_id, page, per_page
     */
    public function index(Request $request): JsonResponse
    {
        $query = StockAdjustment::query()->orderByDesc('created_at');

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        if ($request->filled('actor_user_id')) {
            $query->where('actor_user_id', $request->input('actor_user_id'));
        }

        // Админ, ограниченный категориями (см. CategoryAccess) — видит только
        // строки по СВОИМ товарам. Удалённый товар (product_id уже null,
        // снапшот остался) для такого админа не виден — определить его
        // категорию больше нечем; владелец и неограниченный админ видят всё.
        $allowedCategoryIds = CategoryAccess::expandedIds($request);
        if ($allowedCategoryIds !== null) {
            $query->whereHas('product', fn ($q) => $q->whereIn('category_id', $allowedCategoryIds));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);

        return response()->json($this->paginatedResponse($query->paginate($perPage)));
    }
}
