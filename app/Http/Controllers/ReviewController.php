<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    // =========================================================
    // WIDGET
    // =========================================================

    /**
     * GET /widget/reviews/{product_id}
     * Публичный список опубликованных отзывов на товар.
     */
    public function widgetIndex(Request $request, string $productId): JsonResponse
    {
        $reviews = Review::where('product_id', $productId)
            ->published()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $stats = $this->calcStats($productId);

        return response()->json([
            'data'  => $reviews->map(fn ($r) => $this->formatWidget($r)),
            'stats' => $stats,
        ]);
    }

    /**
     * POST /widget/reviews
     * Публикация отзыва клиентом (требует tenant middleware).
     * Отзыв создаётся со статусом is_published=false — ждёт модерации.
     */
    public function widgetStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id'    => 'required|uuid',
            'rating'        => 'required|integer|min:1|max:5',
            'text'          => 'nullable|string|max:2000',
            'customer_name' => 'required|string|max:100',
            'customer_phone' => 'nullable|string|max:30',
            'order_id'      => 'nullable|uuid',
        ]);

        // Попытка привязать к существующему клиенту по телефону
        $customerId = null;
        if (!empty($data['customer_phone'])) {
            $customer = Customer::where('phone', $data['customer_phone'])->first();
            $customerId = $customer?->id;
        }

        // Один отзыв на товар с одного телефона
        if ($customerId) {
            $exists = Review::where('product_id', $data['product_id'])
                ->where('customer_id', $customerId)
                ->exists();

            if ($exists) {
                return response()->json(['message' => 'Вы уже оставили отзыв на этот товар'], 422);
            }
        }

        $review = Review::create([
            'product_id'    => $data['product_id'],
            'customer_id'   => $customerId,
            'order_id'      => $data['order_id'] ?? null,
            'customer_name' => trim($data['customer_name']),
            'rating'        => $data['rating'],
            'text'          => isset($data['text']) ? trim($data['text']) : null,
            'is_published'  => false,
        ]);

        return response()->json([
            'message' => 'Отзыв отправлен на модерацию',
            'data'    => $this->formatWidget($review),
        ], 201);
    }

    // =========================================================
    // ADMIN
    // =========================================================

    /**
     * GET /admin/reviews
     * Список всех отзывов с фильтрами.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Review::orderByDesc('created_at');

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('rating')) {
            $query->where('rating', $request->rating);
        }

        if ($request->filled('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        $reviews = $query->get();

        return response()->json([
            'data'  => $reviews->map(fn ($r) => $this->format($r)),
            'count' => $reviews->count(),
        ]);
    }

    /**
     * PATCH /admin/reviews/{id}
     * Публикация / скрытие отзыва.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $review = Review::findOrFail($id);

        $data = $request->validate([
            'is_published' => 'required|boolean',
        ]);

        // Используем прямой UPDATE чтобы обойти UPDATED_AT = null
        Review::where('id', $id)->update(['is_published' => $data['is_published']]);
        $review->refresh();

        return response()->json(['data' => $this->format($review)]);
    }

    /**
     * DELETE /admin/reviews/{id}
     * Удаление отзыва.
     */
    public function destroy(string $id): JsonResponse
    {
        Review::findOrFail($id)->delete();

        return response()->json(['message' => 'Отзыв удалён']);
    }

    // =========================================================
    // PRIVATE
    // =========================================================

    private function calcStats(string $productId): array
    {
        $reviews = Review::where('product_id', $productId)->published()->get();

        if ($reviews->isEmpty()) {
            return ['count' => 0, 'average' => null, 'distribution' => []];
        }

        $dist = array_fill(1, 5, 0);
        foreach ($reviews as $r) {
            $dist[$r->rating]++;
        }

        return [
            'count'        => $reviews->count(),
            'average'      => round($reviews->avg('rating'), 1),
            'distribution' => $dist,
        ];
    }

    private function format(Review $r): array
    {
        return [
            'id'            => $r->id,
            'product_id'    => $r->product_id,
            'customer_id'   => $r->customer_id,
            'order_id'      => $r->order_id,
            'customer_name' => $r->customer_name,
            'rating'        => $r->rating,
            'text'          => $r->text,
            'is_published'  => $r->is_published,
            'created_at'    => $r->created_at?->toIso8601String(),
        ];
    }

    private function formatWidget(Review $r): array
    {
        return [
            'id'            => $r->id,
            'customer_name' => $r->customer_name,
            'rating'        => $r->rating,
            'text'          => $r->text,
            'created_at'    => $r->created_at?->toIso8601String(),
        ];
    }
}
