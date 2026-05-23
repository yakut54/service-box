<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * GET /admin/categories
     * Список категорий с дочерними и счётчиком товаров.
     */
    public function index(): JsonResponse
    {
        $categories = Category::withCount('products')
            ->with(['children' => function ($q) {
                $q->withCount('products')->orderBy('sort_order')->orderBy('name');
            }])
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }

    /**
     * POST /admin/categories
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'slug'        => 'nullable|string|max:255',
            'parent_id'   => 'nullable|string',
            'description' => 'nullable|string',
            'image_url'   => 'nullable|string',
            'is_visible'  => 'boolean',
            'sort_order'  => 'integer',
        ]);

        // Ограничение: 1 уровень вложенности — parent_id должен быть top-level
        if (!empty($data['parent_id'])) {
            $parent = Category::find($data['parent_id']);
            if (!$parent || $parent->parent_id !== null) {
                return response()->json(['message' => 'Родитель должен быть категорией верхнего уровня'], 422);
            }
        }

        $data['slug'] = $this->uniqueSlug($data['slug'] ?? null, $data['name']);

        $category = Category::create($data);

        return response()->json(['data' => $category->load('children')], 201);
    }

    /**
     * PATCH /admin/categories/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'slug'        => 'nullable|string|max:255',
            'parent_id'   => 'nullable|string',
            'description' => 'nullable|string',
            'image_url'   => 'nullable|string',
            'is_visible'  => 'boolean',
            'sort_order'  => 'integer',
        ]);

        // Ограничение: 1 уровень вложенности
        if (array_key_exists('parent_id', $data) && !empty($data['parent_id'])) {
            if ($data['parent_id'] === $category->id) {
                return response()->json(['message' => 'Категория не может быть своим родителем'], 422);
            }
            $parent = Category::find($data['parent_id']);
            if (!$parent || $parent->parent_id !== null) {
                return response()->json(['message' => 'Родитель должен быть категорией верхнего уровня'], 422);
            }
        }

        if (isset($data['name']) && empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug(null, $data['name'], $category->id);
        } elseif (!empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $category->name, $category->id);
        }

        $oldImageUrl = $category->image_url;
        $category->update($data);

        if (isset($data['image_url']) && $data['image_url'] !== $oldImageUrl) {
            StorageService::deleteByUrl($oldImageUrl);
        }

        return response()->json(['data' => $category->fresh()->load('children')]);
    }

    /**
     * DELETE /admin/categories/{id}
     *   ?action=hide                                     — скрыть с витрины
     *   ?action=delete&products=nullify                  — удалить, товары без категории
     *   ?action=delete&products=move&target_id=<uuid>    — удалить, товары перенести
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $category = Category::with('children')->findOrFail($id);
        $action   = $request->query('action', 'hide');

        if ($action === 'hide') {
            $category->update(['is_visible' => false]);
            Category::where('parent_id', $category->id)->update(['is_visible' => false]);

            return response()->json(['message' => 'Категория скрыта']);
        }

        if ($action === 'delete') {
            $productsAction = $request->query('products', 'nullify');
            $targetId       = $request->query('target_id');

            // Собираем все ID: родитель + дочерние
            $categoryIds = [$category->id];
            foreach ($category->children as $child) {
                $categoryIds[] = $child->id;
            }

            if ($productsAction === 'move' && $targetId) {
                Product::whereIn('category_id', $categoryIds)->update(['category_id' => $targetId]);
            } else {
                Product::whereIn('category_id', $categoryIds)->update(['category_id' => null]);
            }

            $imageUrls = Category::whereIn('id', $categoryIds)->pluck('image_url')->filter()->values();
            Category::whereIn('id', $categoryIds)->delete();
            $imageUrls->each(fn($url) => StorageService::deleteByUrl($url));

            return response()->json(['message' => 'Категория удалена']);
        }

        return response()->json(['message' => 'Неизвестное действие'], 422);
    }

    /**
     * PATCH /admin/categories/reorder
     */
    public function reorder(Request $request): JsonResponse
    {
        $items = $request->validate([
            'items'              => 'required|array',
            'items.*.id'         => 'required|string',
            'items.*.sort_order' => 'required|integer',
        ])['items'];

        foreach ($items as $item) {
            Category::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['message' => 'Порядок обновлён']);
    }

    /**
     * GET /widget/categories
     */
    public function widgetIndex(): JsonResponse
    {
        $categories = Category::where('is_visible', true)
            ->whereNull('parent_id')
            ->with(['children' => function ($q) {
                $q->where('is_visible', true)->orderBy('sort_order')->orderBy('name');
            }])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'parent_id', 'sort_order']);

        return response()->json(['data' => $categories]);
    }

    private function uniqueSlug(?string $base, string $name, ?string $excludeId = null): string
    {
        $slug     = $base ? Str::slug($base) : Str::slug($name);
        if (!$slug) {
            $slug = 'category';
        }

        $original = $slug;
        $i        = 1;

        while (true) {
            $query = Category::where('slug', $slug);
            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }
            if (!$query->exists()) {
                break;
            }
            $slug = $original . '-' . $i++;
        }

        return $slug;
    }
}
