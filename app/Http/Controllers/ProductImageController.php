<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Доп. фото галереи товара (М1 в PLAN.md). Сам файл уже загружен через
 * POST /admin/upload/image (как и обложка) — здесь только прикрепление
 * готового URL к товару, порядок и удаление.
 */
class ProductImageController extends Controller
{
    public const MAX_IMAGES = 8;

    /**
     * POST /api/admin/products/{product}/images
     */
    public function store(Request $request, string $product): JsonResponse
    {
        $product = Product::findOrFail($product);

        if ($product->images()->count() >= self::MAX_IMAGES) {
            return response()->json([
                'message' => 'Максимум '.self::MAX_IMAGES.' доп. фото на товар',
            ], 422);
        }

        $data = $request->validate([
            'url' => 'required|string|url',
        ], [
            'url.required' => 'Не удалось загрузить фото',
        ]);

        $nextSortOrder = ((int) $product->images()->max('sort_order')) + 1;

        $image = $product->images()->create([
            'url' => $data['url'],
            'sort_order' => $nextSortOrder,
        ]);

        return response()->json(['data' => $image], 201);
    }

    /**
     * PATCH /api/admin/products/{product}/images/reorder
     */
    public function reorder(Request $request, string $product): JsonResponse
    {
        $product = Product::findOrFail($product);

        $items = $request->validate([
            'items'              => 'required|array',
            'items.*.id'         => 'required|string',
            'items.*.sort_order' => 'required|integer',
        ])['items'];

        foreach ($items as $item) {
            $product->images()->where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['message' => 'Порядок обновлён']);
    }

    /**
     * DELETE /api/admin/products/{product}/images/{image}
     */
    public function destroy(string $product, string $image): JsonResponse
    {
        $product = Product::findOrFail($product);
        $image = $product->images()->where('id', $image)->firstOrFail();

        $url = $image->url;
        $image->delete();
        StorageService::deleteByUrl($url);

        return response()->json(['message' => 'Фото удалено']);
    }
}
