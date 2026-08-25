<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerSession;
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
            'data'      => $reviews->map(fn ($r) => $this->formatWidget($r)),
            'stats'     => $stats,
            // Заполнено только если пришёл валидный X-Phone-Session (мобильное
            // приложение) — говорит "я уже отзывался на этот товар", включая
            // отзывы, ещё не прошедшие модерацию. Веб-виджет анонимный,
            // заголовок не шлёт — для него всегда null, поведение не меняется.
            'my_review' => $this->findMyReview($request, $productId),
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

        // Приоритет — проверенная сессия (мобильное приложение): телефон
        // подделать нельзя, в отличие от поля customer_phone в теле запроса.
        // Веб-виджет анонимный, сессию не шлёт — там остаётся старое
        // поведение "верим полю как есть".
        $customerId = $this->resolveCustomer($request)?->id;
        if (!$customerId && !empty($data['customer_phone'])) {
            $phone    = Customer::normalizePhone($data['customer_phone']);
            $customer = Customer::where('phone', $phone)->first();
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

    /**
     * X-Phone-Session — та же долгая (60 дней) сессия мобильного приложения,
     * что и на /widget/orders/mine (см. VerifyPhoneSession). Здесь без
     * миддлвари, вручную и необязательно — эндпоинт должен остаться
     * доступным анонимно (список отзывов, веб-виджет).
     */
    private function resolveCustomer(Request $request): ?Customer
    {
        $token = $request->header('X-Phone-Session');
        if (!$token) {
            return null;
        }

        return CustomerSession::with('customer')
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first()?->customer;
    }

    private function findMyReview(Request $request, string $productId): ?array
    {
        $customer = $this->resolveCustomer($request);
        if (!$customer) {
            return null;
        }

        $review = Review::where('product_id', $productId)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$review) {
            return null;
        }

        return [
            'id'           => $review->id,
            'rating'       => $review->rating,
            'text'         => $review->text,
            'is_published' => $review->is_published,
            'created_at'   => $review->created_at?->toIso8601String(),
        ];
    }

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
