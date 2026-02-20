<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use App\Services\DiscountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DiscountController extends Controller
{
    public function __construct(private readonly DiscountService $discountService) {}

    // GET /admin/discounts
    public function index(Request $request): JsonResponse
    {
        $query = Discount::query()->latest('created_at');

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        return response()->json([
            'data' => $query->get()->map(fn ($d) => $this->format($d)),
        ]);
    }

    // POST /admin/discounts
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                => 'required|string|max:255',
            'type'                => 'required|in:percent,fixed',
            'value'               => 'required|integer|min:1',
            'code'                => 'nullable|string|max:50',
            'scope'               => 'required|in:cart,product,category',
            'scope_value'         => 'nullable|string|max:255',
            'min_order_amount'    => 'nullable|integer|min:0',
            'max_discount_amount' => 'nullable|integer|min:1',
            'usage_limit'         => 'nullable|integer|min:1',
            'per_user_limit'      => 'nullable|integer|min:0',
            'is_active'           => 'boolean',
            'starts_at'           => 'nullable|date',
            'ends_at'             => 'nullable|date|after_or_equal:starts_at',
        ]);

        if (!empty($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));

            if (Discount::where('code', $data['code'])->exists()) {
                return response()->json(['message' => 'Промокод уже существует'], 422);
            }
        }

        // percent value sanity check
        if ($data['type'] === 'percent' && $data['value'] > 100) {
            return response()->json(['message' => 'Процент не может превышать 100'], 422);
        }

        $discount = Discount::create($data);

        return response()->json(['data' => $this->format($discount)], 201);
    }

    // GET /admin/discounts/{id}
    public function show(string $id): JsonResponse
    {
        $discount = Discount::findOrFail($id);

        return response()->json(['data' => $this->format($discount)]);
    }

    // PATCH /admin/discounts/{id}
    public function update(Request $request, string $id): JsonResponse
    {
        $discount = Discount::findOrFail($id);

        $data = $request->validate([
            'name'                => 'sometimes|string|max:255',
            'type'                => 'sometimes|in:percent,fixed',
            'value'               => 'sometimes|integer|min:1',
            'code'                => 'nullable|string|max:50',
            'scope'               => 'sometimes|in:cart,product,category',
            'scope_value'         => 'nullable|string|max:255',
            'min_order_amount'    => 'nullable|integer|min:0',
            'max_discount_amount' => 'nullable|integer|min:1',
            'usage_limit'         => 'nullable|integer|min:1',
            'per_user_limit'      => 'nullable|integer|min:0',
            'is_active'           => 'boolean',
            'starts_at'           => 'nullable|date',
            'ends_at'             => 'nullable|date',
        ]);

        if (!empty($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));
        }

        $discount->update($data);

        return response()->json(['data' => $this->format($discount->fresh())]);
    }

    // DELETE /admin/discounts/{id}
    public function destroy(string $id): JsonResponse
    {
        Discount::findOrFail($id)->delete();

        return response()->json(['message' => 'Скидка удалена']);
    }

    // POST /widget/discount/validate
    // Used by the widget to validate a promo code in real time (before order creation)
    public function widgetValidate(Request $request): JsonResponse
    {
        $request->validate([
            'code'            => 'required|string',
            'cart_amount'     => 'required|integer|min:1',
            'customer_phone'  => 'nullable|string',
        ]);

        try {
            $result = $this->discountService->validate(
                $request->code,
                $request->cart_amount,
                $request->customer_phone,
            );

            $d = $result['discount'];

            return response()->json([
                'valid'           => true,
                'discount_id'     => $d->id,
                'name'            => $d->name,
                'type'            => $d->type,
                'value'           => $d->value,
                'discount_amount' => $result['amount'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'valid'   => false,
                'message' => $e->errors()['discount_code'][0] ?? 'Промокод недействителен',
            ], 422);
        }
    }

    private function format(Discount $d): array
    {
        return [
            'id'                  => $d->id,
            'name'                => $d->name,
            'type'                => $d->type,
            'value'               => $d->value,
            'code'                => $d->code,
            'scope'               => $d->scope,
            'scope_value'         => $d->scope_value,
            'min_order_amount'    => $d->min_order_amount,
            'max_discount_amount' => $d->max_discount_amount,
            'usage_limit'         => $d->usage_limit,
            'usage_count'         => $d->usage_count,
            'per_user_limit'      => $d->per_user_limit,
            'is_active'           => $d->is_active,
            'starts_at'           => $d->starts_at?->toIso8601String(),
            'ends_at'             => $d->ends_at?->toIso8601String(),
            'created_at'          => $d->created_at?->toIso8601String(),
        ];
    }
}
