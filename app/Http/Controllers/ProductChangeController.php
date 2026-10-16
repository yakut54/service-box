<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\Paginates;
use App\Http\Controllers\Concerns\TracksSeenCursor;
use App\Models\ProductChange;
use App\Services\TableExport;
use App\Support\CategoryAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /** scopedQuery() + фильтры product_id/actor_user_id — общие для index() и export(). */
    private function filteredQuery(Request $request): Builder
    {
        $query = $this->scopedQuery($request);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        if ($request->filled('actor_user_id')) {
            $query->where('actor_user_id', $request->input('actor_user_id'));
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
        $query = $this->filteredQuery($request)->orderByDesc('created_at');

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

    /**
     * GET /api/admin/product-changes/export?format=csv|xlsx
     * Те же query-фильтры (product_id/actor_user_id), что и у index() — то,
     * что отфильтровано на экране, то и выгружается (как у заказов/клиентов,
     * см. OrderController::export/CustomerController::export).
     */
    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filteredQuery($request)->orderByDesc('created_at')->get();

        $fieldLabels = [
            'stock'         => 'Остаток',
            'price'         => 'Цена',
            'compare_price' => 'Старая цена',
            'name'          => 'Название',
            'category'      => 'Категория',
            'active'        => 'Показ в каталоге',
        ];
        $actionLabels = [
            'created' => 'Товар создан',
            'deleted' => 'Товар удалён',
        ];

        // Та же логика, что formatProductChangeValue() на фронте
        // (admin/src/shared/lib/format.ts) — кроме цены: там "92 ₽" для
        // экрана, здесь голое число "92.00" для Excel (тот же приём, что у
        // total_spent в CustomerController::export — таблицу удобнее
        // считать, когда колонка действительно числовая).
        $formatValue = function (?string $field, ?string $unit, ?string $raw): string {
            if ($raw === null) {
                return '';
            }
            if ($field === 'stock') {
                if ($unit !== 'g') {
                    return $raw;
                }
                $kg = number_format(((float) $raw) / 1000, 1, '.', '');
                return (str_ends_with($kg, '.0') ? substr($kg, 0, -2) : $kg) . ' кг';
            }
            if ($field === 'price' || $field === 'compare_price') {
                return number_format(((float) $raw) / 100, 2, '.', '');
            }
            if ($field === 'active') {
                return $raw === '1' ? 'Показан' : 'Скрыт';
            }
            return $raw;
        };

        $headers = ['Товар', 'Вариант', 'Действие', 'Было', 'Стало', 'Сотрудник', 'Причина', 'Дата'];

        $exportRows = $rows->map(function (ProductChange $c) use ($fieldLabels, $actionLabels, $formatValue) {
            $isUpdate = $c->action === 'updated';

            return [
                $c->product_name,
                $c->variant_label ?? '',
                $isUpdate ? ($fieldLabels[$c->field] ?? $c->field) : ($actionLabels[$c->action] ?? $c->action),
                $isUpdate ? $formatValue($c->field, $c->unit, $c->old_value) : '',
                $isUpdate ? $formatValue($c->field, $c->unit, $c->new_value) : '',
                $c->actor_name,
                $c->reason ?? '',
                $c->created_at->format('d.m.Y H:i'),
            ];
        });

        return TableExport::stream($request->input('format', 'csv'), 'product_changes', $headers, $exportRows);
    }
}
