<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Сообщить о поступлении». Байер (сессия по телефону) подписывается на товар
 * или конкретный вариант; уведомление уходит из Jobs\NotifyBackInStock, когда
 * остаток снова станет положительным.
 */
class StockSubscriptionController extends Controller
{
    // POST /widget/products/{product}/notify-me   body: { variant_id?: uuid }
    public function store(Request $request, string $product): JsonResponse
    {
        $customer = $request->attributes->get('customer');

        $productModel = Product::findOrFail($product);

        $variantId = $request->input('variant_id');
        if ($variantId !== null) {
            $ok = ProductVariant::where('product_id', $productModel->id)
                ->whereKey($variantId)
                ->exists();
            if (!$ok) {
                return response()->json(['message' => 'Вариант не найден'], 422);
            }
        }

        $sub = StockSubscription::where('product_id', $productModel->id)
            ->where('customer_id', $customer->id)
            ->when($variantId === null,
                fn ($q) => $q->whereNull('variant_id'),
                fn ($q) => $q->where('variant_id', $variantId),
            )
            ->first();

        if ($sub) {
            $sub->update(['notified_at' => null]); // повторная подписка
        } else {
            StockSubscription::create([
                'product_id'  => $productModel->id,
                'variant_id'  => $variantId,
                'customer_id' => $customer->id,
            ]);
        }

        return response()->json(['message' => 'Сообщим, когда товар появится']);
    }
}
