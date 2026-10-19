<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\Paginates;
use App\Http\Controllers\Concerns\TracksSeenCursor;
use App\Models\CategoryChange;
use App\Services\TableExport;
use App\Support\CategoryAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Журнал ручных правок категорий (см. App\Services\CategoryChangeLog) —
 * только чтение, строки пишутся из CategoryController через сервис, не
 * отсюда. Калька ProductChangeController для дерева категорий.
 */
class CategoryChangeController extends Controller
{
    use Paginates;
    use TracksSeenCursor;

    /**
     * Админ, ограниченный категориями, вообще не может управлять
     * категориями (см. CategoryController::denyIfCategoryScoped) — но
     * читать список он может, поэтому журнал тоже режем до его набора.
     */
    private function scopedQuery(Request $request): Builder
    {
        $query = CategoryChange::query();

        $allowedCategoryIds = CategoryAccess::expandedIds($request);
        if ($allowedCategoryIds !== null) {
            $query->whereIn('category_id', $allowedCategoryIds);
        }

        return $query;
    }

    /** scopedQuery() + фильтр category_id/actor_user_id — общие для index() и export(). */
    private function filteredQuery(Request $request): Builder
    {
        $query = $this->scopedQuery($request);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('actor_user_id')) {
            $query->where('actor_user_id', $request->input('actor_user_id'));
        }

        return $query;
    }

    /**
     * GET /api/admin/category-changes
     *
     * Query params: category_id, actor_user_id, page, per_page
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->filteredQuery($request)->orderByDesc('created_at');

        $perPage = min((int) $request->input('per_page', 25), 100);

        return response()->json([
            ...$this->paginatedResponse($query->paginate($perPage)),
            'category_changes_last_seen_at' => $this->seenAt($request, 'category_changes')?->toIso8601String(),
        ]);
    }

    /**
     * GET /api/admin/category-changes/pending-count
     * Собственные правки пользователя в счётчик не попадают — та же логика,
     * что у ProductChangeController::pendingCount.
     */
    public function pendingCount(Request $request): JsonResponse
    {
        $query = $this->scopedQuery($request)->where('actor_user_id', '!=', $request->user()->id);
        $count = $this->pendingCountSince($request, 'category_changes', $query);

        return response()->json(['count' => $count]);
    }

    /**
     * POST /api/admin/category-changes/mark-seen
     */
    public function markSeen(Request $request): JsonResponse
    {
        $this->markSeenNow($request, 'category_changes');

        return response()->json(['message' => 'ok']);
    }

    /**
     * GET /api/admin/category-changes/export?format=csv|xlsx
     */
    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filteredQuery($request)->orderByDesc('created_at')->get();

        $fieldLabels = [
            'name'           => 'Название',
            'parent_id'      => 'Родительская категория',
            'description'    => 'Описание',
            'image_url'      => 'Фото',
            'visible'        => 'Показ в каталоге',
            'age_restricted' => '18+',
            'no_return'      => 'Не подлежит возврату',
        ];
        $actionLabels = [
            'created' => 'Категория создана',
            'deleted' => 'Категория удалена',
        ];

        $formatValue = function (?string $field, ?string $raw): string {
            if ($field === 'image_url') {
                return $raw ? 'задано' : 'не задано';
            }
            if ($raw === null) {
                return '';
            }
            if ($field === 'visible') {
                return $raw === '1' ? 'Показана' : 'Скрыта';
            }
            if ($field === 'age_restricted' || $field === 'no_return') {
                return $raw === '1' ? 'Да' : 'Нет';
            }
            return $raw;
        };

        $headers = ['Категория', 'Действие', 'Было', 'Стало', 'Сотрудник', 'Дата'];

        $exportRows = $rows->map(function (CategoryChange $c) use ($fieldLabels, $actionLabels, $formatValue) {
            $isUpdate = $c->action === 'updated';

            return [
                $c->category_name,
                $isUpdate ? ($fieldLabels[$c->field] ?? $c->field) : ($actionLabels[$c->action] ?? $c->action),
                $isUpdate ? $formatValue($c->field, $c->old_value) : '',
                $isUpdate ? $formatValue($c->field, $c->new_value) : '',
                $c->actor_name,
                $c->created_at->format('d.m.Y H:i'),
            ];
        });

        return TableExport::stream($request->input('format', 'csv'), 'category_changes', $headers, $exportRows);
    }

    /**
     * DELETE /api/admin/category-changes
     * Выборочная очистка журнала — только владелец (роут защищён middleware
     * 'owner'). Та же защита, что у ProductChangeController::destroy.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'required|uuid',
        ]);

        $deleted = $this->scopedQuery($request)->whereIn('id', $data['ids'])->delete();

        $shop = $request->attributes->get('shop');
        \App\Events\ShopChannelEvent::dispatch($shop->id, 'category_changes.updated');
        \App\Events\NavCountsUpdated::dispatch($shop->id);

        return response()->json(['message' => "Удалено записей: {$deleted}", 'deleted' => $deleted]);
    }
}
